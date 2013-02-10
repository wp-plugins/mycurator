<?php
/* MyCurator Local Process
 * This file contains the code to read all sources and call cloud services to render and classify the article.  It will retrieve and post for all topics
 * It is multisite aware, but will only process for the site that it is called for using ?blogid = x
*/

//add action to set cache duration on simplepie
add_action('wp_feed_options', 'mct_ai_set_simplepie');

define ('MCT_AI_OLDPOSTSREAD', '7');
//this should be shorter than the interval in which we run cron, but longer than the longest running time of the process
define ('MCT_AI_PIE_CACHE',3600);  
    

function mct_ai_process_site(){
    //This function will process all topics for a site/blog
    //It is started from the Run AI Process button on the Topics page
    global $blog_id, $wpdb, $ai_topic_tbl, $ai_postsread_tbl, $ai_sl_pages_tbl, $ai_logs_tbl, $mct_ai_optarray;
    
    set_time_limit(300);  //Boost the time limit for execution
    mct_ai_log('Blog',MCT_AI_LOG_PROCESS, 'Start Processing Site: '.$blog_id.'  ', '');
    //Clean postsread - also does logs and training targets
    mct_ai_clean_postsread(true);

    //Loop on all train and active topics in this site
    $sql = "SELECT *
            FROM $ai_topic_tbl
            WHERE topic_status != 'Inactive'";
    $topics = $wpdb->get_results($sql, ARRAY_A);
    if (empty($topics)){
        return;
    }
    foreach ($topics as $topic){
        mct_ai_process_topic($topic);
    } //end for each topic
    mct_ai_log('Blog',MCT_AI_LOG_PROCESS, 'End Site Processing: '.$blog_id.'  ', '');
}

function mct_ai_process_topic($topic){ 
    //Process all feeds and items within a topic
    //$topic is an array with each field from the topics file
    global $blog_id, $wpdb, $ai_topic_tbl, $ai_postsread_tbl, $ai_sl_pages_tbl, $ai_logs_tbl, $mct_ai_optarray;
    
    mct_ai_log($topic['topic_name'],MCT_AI_LOG_PROCESS, 'Start Processing Topic ', '');
    //Set up the topic info for the cloud service
    if (!mct_ai_cloudtopic($topic)) return; 
    //For this topic, get the sources
    $sources = array_map('trim',explode(',',$topic['topic_sources']));
    if (empty($sources)){
        return;  //Nothing to read
    }
    
    foreach ($sources as $source){
        //For this source, get each feed
        $args = array(
            'category' => $source,
            'hide_invisible' => false
        );
        $feeds = get_bookmarks($args);
        if (empty($feeds)){
            continue;
        }
        foreach ($feeds as $feed){
            if (empty($feed->link_rss)){
                mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'No feed rss link for feed: '.$feed->link_name, $feed->link_rss);
                continue;  //no feed in link
            }
            //replace &amp; with & - from db sanitization
            $feed->link_rss = preg_replace('[&amp;]','&',$feed->link_rss);
            //Process Feed
            $nowdate = new DateTime('now');
            $thefeed = fetch_feed($feed->link_rss);
            if (is_wp_error($thefeed)){
                mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, $thefeed->get_error_message(),$feed->link_rss);
                continue;  //feed error
            }
            mct_ai_log($topic['topic_name'],MCT_AI_LOG_PROCESS, 'Processing Feed ', $feed->link_name);
            $anynewposts = false;
            $onlyoldposts = true;
            //Process each item in the rss feed
            foreach ($thefeed->get_items() as $item){
                //See if old post
                $postdate = new DateTime($item->get_date());
                $postdate->modify('+'.MCT_AI_OLDPOSTSREAD.' day');  //add number of days we keep posts read around
                // $a = $nowdate->diff($postdate,true)->days; for version 5.3 or later only
                if ($postdate < $nowdate){
                    $postdate->modify('+60 day');
                    if ($postdate > $nowdate){
                        $onlyoldposts = false;
                    }
                    continue;  //Post older than what we keep track of
                }
                $anynewposts = true; // Something fits our timeframe
                unset($postdate);
                $post_arr = array();  //will hold the post info as we build it
                $page = mct_ai_get_page($item, $topic, $post_arr);  //try and get the page from the postsread file, 
                if (empty($page)){
                    continue;  //no more work, try the next item
                }
                $new_page = false;
                if ($page == "Not Here") $new_page = true;  //Flag to update posts read table, tell cloud to render the page from the link
                //Call cloud services to process
                $postit = mct_ai_cloudclassify($page, $topic, $post_arr);

                //Set up page in postsread if wasn't here - whether or not we are going to post 
                if ($new_page) {
                    $page = $post_arr['page'];
                    //update the style sheet with the local copy
                    $page = str_replace("mct_ai_local_style",plugins_url('MyCurator_page.css',__FILE__), $page);
                    mct_ai_setPRpage($page, $topic, $post_arr);  //Need to update posts read if this is a new page
                }
                //Post the new entry if good response
                if ($postit) mct_ai_post_entry($topic, $post_arr, $page);  //post entries

            } //end for each item
            $thefeed->__destruct(); 
            unset($thefeed);
            unset($nowdate);
            //Log if only old posts
            if (!$anynewposts && $onlyoldposts) {
                mct_ai_log($topic['topic_name'],MCT_AI_LOG_ACTIVITY, 'All posts older than 60 days',$feed->link_name);
            }
        }  //end for each feed
    } //end for each source
}  
        

function mct_ai_get_page($item, $topic, &$post_arr){
    global $wpdb, $blog_id, $ai_postsread_tbl, $ai_sl_pages_tbl, $mct_ai_stored_page_id, $mct_ai_current_link;
    
    //item is a simplepie object
    //Check if we've read the page already and use it if so
    //If page is not here, return "Not Here" to tell cloud services to grab the page too
    //Return '' if nothing to process because of errors or we've processed it previously
    
    //clean up the link and check if it is from an excluded domain from this topic
    $ilink = trim($item->get_permalink());
    //replace &amp; with & - from simple pie sanitization
    $ilink = preg_replace('[&amp;]','&',$ilink);
    //If google alert feed, get embedded link
    $cnt = preg_match('{www\.google\.com/url\?sa=X&q=([^&]*)&ct}',$ilink,$matches);
    if ($cnt) {
        $ilink = trim(rawurldecode($matches[1]));
    }
    //If google news feed, get embedded link
    if (stripos($ilink,'news.google.com/news') !== false){
        $cnt = preg_match('{&url=(.*)$}',$ilink,$matches);
        if ($cnt) {
            $ilink = trim(rawurldecode($matches[1]));
        }
    }
    //If twitter search feed, get embedded links or return '' if none
    if (stripos($ilink,'twitter.com') !== false){
        $desc = $item->get_description();
        $cnt = preg_match('{http://t.co/([^"]*)"}',$desc,$matches);
        if ($cnt) {
            $elink = $ilink = trim('http://t.co/'.$matches[1]);  //get just first link
            $ilink = mct_ai_tw_expandurl($ilink);
            if ($ilink == '') {
                mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, "Could Not Resolve Twitter Link",$elink);
                return '';
            }
            //mct_ai_log($topic['topic_name'],MCT_AI_LOG_ACTIVITY, 'Twitter Article Found',$ilink);
        } else {
            return '';  //No link found
        }
    }
    //Check if excluded domain for this topic
    $dom_array = explode("\n",$topic['topic_skip_domains']);
    $linkhost = parse_url($ilink, PHP_URL_HOST);  //only check the host/domain as we could get problems with path/query
    foreach ($dom_array as $dom){
        $dom = rtrim($dom);
        if (stripos($linkhost,$dom) !== false) {
            return '';  //excluded domain so skip this item
        }
    }
    $this_topic = trim(strval($blog_id)).':'.trim($topic['topic_id']); // Set up topic key with site and topic
    //check postsread for the item
    //Use double quotes if url has single quote
    if (stripos($ilink,"'") === false){
        $sql = "SELECT `pr_id`, `pr_page_content`, `pr_topics`
            FROM $ai_postsread_tbl
            WHERE pr_url = '$ilink'";
    } else {
        $sql = 'SELECT `pr_id`, `pr_page_content`, `pr_topics`
            FROM '.$ai_postsread_tbl.'
            WHERE pr_url = "$ilink"';
    }
    $pr_row = $wpdb->get_row($sql, ARRAY_A);
    if ($pr_row != NULL){
        //check to see if this topic is in list of topics read
        if (stripos($pr_row['pr_topics'],$this_topic) !== false){
            //We've processed this feed, update date and return blank to signal no more work
            $wpdb->update($ai_postsread_tbl,array('pr_date' => current_time('mysql')), array('pr_id' => $pr_row['pr_id']));
            return '';
        } else {
            // Not processed yet, so get the stored page and update with our topic
            $page_id = $pr_row['pr_id'];
            $pr_row['pr_topics'] .= ','.$this_topic;  
            $upd_array = array(
                'pr_topics' => $pr_row['pr_topics'],
                'pr_date' => current_time('mysql')
            );
            $wpdb->update($ai_postsread_tbl,$upd_array, array('pr_id' => $page_id));  //update postsread with topic/date
            //get stored page
            $page = $pr_row['pr_page_content'];
        }
    } else {
        //Not read yet, so signal we need it from cloud services
        $page = 'Not Here';
    }
    $post_arr['current_link'] = $ilink;
    
    return $page;
}

function mct_ai_cloudtopic($topic){
    //This function calls the cloud service and tells it the topic we will be processing, 
    //sending the relevant data to support processing
    
    $response = mct_ai_callcloud('Topic', $topic, $topic);
    //response is json decoded
    if ($response == NULL) return false; //error already logged
    if ($response->LOG == 'OK') return true;
    $log = get_object_vars($response->LOG);
    //Log the error and return false
    mct_ai_log($log['logs_topic'], $log['logs_type'], $log['logs_msg'], $log['logs_url']);
    return false;
}

function mct_ai_cloudclassify($page,$topic,&$post_arr){
    //This function calls the cloud service and has it process a page for this topic
    //If page is "Not Here", it will return the new page
    
    $post_arr['page'] = $page;
    
    $response = mct_ai_callcloud('Classify', $topic, $post_arr);
    if ($response == NULL) return false; //error already logged
    if (!empty($response->postarr)) $post_arr = get_object_vars($response->postarr);  //may have page even if error
    if (!empty($response->LOG)) {
        $log = get_object_vars($response->LOG);
        //Log the error and return false
        mct_ai_log($log['logs_topic'], $log['logs_type'], $log['logs_msg'], $log['logs_url']);
        return false;    
    }

    return true;
}

function mct_ai_callcloud($type,$topic,$postvals){
    //This function calls the cloud service with the token
    global $mct_ai_optarray;
    
    $ref = home_url();
    if (is_multisite()) $ref = network_home_url();
    
    $cloud_data = array(
        'args' => $postvals,
        'token' => $mct_ai_optarray['ai_cloud_token'],
        'type' => $type,
        'utf8' => $mct_ai_optarray['ai_utf8'], //which 'word' processing to use
        'ver' => '1.3.0',
        'gzip' => 1, //enable compression
        'topic_id' => strval($topic['topic_id'])
        );
    $cloud_json = json_encode($cloud_data);
    //compression/header
    if (strlen($cloud_json) > 1000) {
        $cloud_json = gzcompress($cloud_json);
        $hdr = array(                                                                          
        'Content-Type: application/json',                                                                                
        'Content-Length: ' . strlen($cloud_json),
        'Content-Encoding: gzip');
    } else {
        $hdr = array(                                                                          
        'Content-Type: application/json',                                                                                
        'Content-Length: ' . strlen($cloud_json));
    }
    $useragent = $type;
    if (isset($postvals['getit'])) $useragent .= " GetIt";
    $ch = curl_init();
    // SET URL FOR THE POST FORM LOGIN
    curl_setopt($ch, CURLOPT_URL, 'http://tgtinfo.net'); //'http://tgtinfo.net'
    // ENABLE HTTP POST
    curl_setopt ($ch, CURLOPT_POST, 1);
    // SET POST FIELD to the content
    curl_setopt($ch, CURLOPT_REFERER, $ref);
    curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
    curl_setopt ($ch, CURLOPT_POSTFIELDS, $cloud_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);                                                                       
    //Force curl to return results and not display
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    // EXECUTE REQUEST
    $cloud_response = curl_exec ($ch);
    $curlinfo = curl_getinfo($ch);
    curl_close($ch);
    if ($curlinfo == false) {
        mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'Call to Cloud Services Failed',$type);
        return false;
    }
    if ($curlinfo['http_code'] != 200) {
        mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'HTTP error: '.strval($curlinfo['http_code']),$type);
        return false;
    } 
    //Cloud Response holds the returned Json data, decode and return
    if (stripos($curlinfo['content_type'],'gzip')!== false) {
        $cloud_response = gzuncompress($cloud_response);
    }
    $json_response = json_decode($cloud_response);
    if ($json_response == NULL) {
        mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'Invalid JSON Object Returned',$type);
    }
    return $json_response;
   
}

function mct_ai_setPRpage($page, $topic, $post_arr){
    //Set the new page into the postsread table
    global $wpdb, $blog_id, $ai_postsread_tbl;
    
    if (empty($page)) {
        //already logged
        return '';
    }
    $ilink = $post_arr['current_link'];
    $this_topic = trim(strval($blog_id)).':'.trim($topic['topic_id']); // Set up topic key with site and topic
    //insert into postsread
    $val_array = array(
        'pr_topics' => $this_topic,
        'pr_date' => current_time('mysql'),
        'pr_page_content' => $page,
        'pr_url' => trim($ilink)
    );
    $wpdb->insert($ai_postsread_tbl, $val_array);
    $page_id = $wpdb->insert_id;
    if (!$page_id){
        //log error and return
        mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'Could not insert into Postsread DB',$ilink);
        return '';
    }
}


function mct_ai_post_entry($topic, $post_arr, $page){
    global $wpdb, $ai_sl_pages_tbl, $blog_id, $user_id, $mct_ai_optarray;
    //if filter type just post to blog (cat/tag) or Targets if training
    //else if relevance type and active: post good to blog(cat/tag), unknown to training
    //  else post training for all 
    //if relevance also log ai probs
    
    //Good item, so save a copy of page (postsread version will go away eventually) and setredirect value
    $wpdb->insert($ai_sl_pages_tbl, array ('sl_page_content' => $page));
    $page_id = $wpdb->insert_id;
    if (!$page_id) {
        //log error - couldn't save the page
        mct_ai_log($topic['topic_name'],MCT_AI_LOG_ERROR, 'Could not save page in DB',$post_arr['current_link']);
    } 
    //Set the redirect link
    if (is_multisite()){
        if ($blog_id == 1){
            $link_redir = network_site_url().'blog/'.MCT_AI_REDIR.'/'.trim(strval($page_id));
        } else {
            $link_redir = site_url().'/'.MCT_AI_REDIR.'/'.trim(strval($page_id));
        }
    } else {
        $link_redir = site_url().'/'.MCT_AI_REDIR.'/'.trim(strval($page_id));
    }
    //Set up the content
    mct_ai_getpostcontent($page, $post_arr,$topic['topic_type']);
    if ($mct_ai_optarray['ai_show_orig']){
        $post_arr['orig_link'] = mct_ai_formatlink($post_arr);
        $post_content = $post_arr['article'].'<p id="mct-ai-attriblink">'.$post_arr['orig_link'].'</p>';
        if (empty($mct_ai_optarray['ai_orig_text'])) {
            $post_content = str_replace("Click here to view original web page at ",$mct_ai_optarray['ai_orig_text'],$post_content); //remove space too
        } else {
            $post_content = str_replace("Click here to view original web page at",$mct_ai_optarray['ai_orig_text'],$post_content);  // leave space
        }
    } else {
        $post_content = $post_arr['article'].'<p id="mct-ai-attriblink"><a href="'.$link_redir.'" >Click here to view full article</a></p>';
        $post_content = str_replace("Click here to view full article",$mct_ai_optarray['ai_save_text'],$post_content);
    }
    $post_content = apply_filters('mct_ai_postcontent',$post_content);
    // Get an image if we can - 1st match of appropriate size
    $image = '';
    if (!empty($mct_ai_optarray['ai_save_thumb'])  || !empty($mct_ai_optarray['ai_post_img'])) {
        $regexp1 = '{<img [^>]*src\s*=\s*("|\')([^"\']*)("|\')[^>]*>}i'; 
        $pos = preg_match_all($regexp1,$page,$matchall, PREG_SET_ORDER);
        if ($pos) {
            foreach ($matchall as $matches) {
                $size = @getimagesize($matches[2]);
                if ($size && $size[0] >= 25 && $size[1] >= 25){  //excludes small pngs, icons, pixels
                    $image = $matches[2];
                    break;
                }
            }
        }
    }
    //Set up the values, not set are defaults
    //Check for a user, if not we are in cron process so set  user for the site
    if (isset($mct_ai_optarray['ai_post_user']) && $mct_ai_optarray['ai_post_user']) {
        $useris = get_user_by('login',$mct_ai_optarray['ai_post_user']);
        if ($useris) {
            $pa = $useris->ID;
        } else {
            $pa = 1;
        }
    } else {        
        $useradms = get_users(array('role' => 'administrator'));
        if (empty($useradms)){
            $pa = 1;
        } else {
            $first = $useradms[0];
            $pa = $first->ID;
        }
    }
    wp_set_current_user($pa);
    $details = array(
      'post_content'  => $post_content,
      'post_author' => $pa,
      'post_title'  =>  $post_arr['title'],
      'post_name' => sanitize_title($post_arr['title']),
      'post_status' => 'publish'
    );
    //Save the excerpt field?
    //ai_nosave_excerpt
    if ($mct_ai_optarray['ai_nosave_excerpt']) {
        //don't save
    } else {
        $details['post_excerpt'] = $post_content;
    }
    //Use topic & aiclass in all cases
    if ($topic['topic_type'] != 'Relevance'){
        $details['tax_input'] = array (  //add topic name 
        'topic' => $topic['topic_name']
        );
    }
    if ($topic['topic_type'] == 'Relevance'){
        $details['tax_input'] = array (  //add topic name 
        'topic' => $topic['topic_name'],
        'ai_class' => $post_arr['classed'] //add ai class
        );
    }
    //check if active using relevance engine, but post is bad or unknown
    $rel_not_good = false;  
    if ($topic['topic_status'] == 'Active' && 
            $topic['topic_type'] == 'Relevance' && 
            $post_arr['classed'] != 'good'){
        $rel_not_good = true;
    }
    //Training or not relevant but active
    $post_msg = '';
    if ($topic['topic_status'] == 'Training'  || $rel_not_good){

        $details['post_type'] = 'target_ai'; //post as a target
        $post_id = wp_insert_post($details);
        if (!empty($post_arr['tags'])){
            update_post_meta($post_id,'mct_ai_tag_search2',$post_arr['tags']);
        }
        $post_msg = $post_arr['classed'];  
    }
    //Active and relevant
    if ($topic['topic_status'] == 'Active' && !$rel_not_good){
        if (empty($post_arr['tags'])){
            $tagterm = get_term($topic['topic_tag'],'post_tag');
            $details['tags_input'] = array($tagterm->name);
        } else {
            $details['tags_input'] = $post_arr['tags'];
        }
        $details['post_category'] = array($topic['topic_cat']);
        $post_id = wp_insert_post($details);
        $post_msg = "Live";
    }
    
    //update post meta
    update_post_meta($post_id,'mct_sl_origurl',array($post_arr['current_link']));
    update_post_meta($post_id,'mct_sl_newurl',array($link_redir));
    //update relevance classification 
    if ($topic['topic_type'] == 'Relevance'){
        update_post_meta($post_id, 'mct_ai_relevance',array(
            'classed' => $post_arr['classed'],
            'good' => sprintf('%.6f',$post_arr['good']),
            'bad' => sprintf('%.6f', $post_arr['bad']),
            'dbsize' => sprintf('%.0f',$post_arr['dbsize'])
        ));
    }
    //update the image if found as the featured image or inserted image for the post 
    if ($topic['topic_type'] == 'Video' && !empty($post_arr['yt_thumb']) && !empty($mct_ai_optarray['ai_save_thumb'])){
        $thumb_id = mct_ai_postthumb($post_arr['yt_thumb'],$post_id);
    } elseif (!empty($image)){
        $thumb_id = mct_ai_postthumb($image,$post_id);
    }
    if ($thumb_id) {  
        if ($mct_ai_optarray['ai_save_thumb']) update_post_meta( $post_id, '_thumbnail_id', $thumb_id );
        //Add image to start of post if set
        if (isset($mct_ai_optarray['ai_post_img']) && $mct_ai_optarray['ai_post_img'] ){
            $details = array();
            $url = wp_get_attachment_url($thumb_id);
            $align = $mct_ai_optarray['ai_img_align'];
            $size = $mct_ai_optarray['ai_img_size'];
            $src = wp_get_attachment_image_src($thumb_id,$size);
            if (!$src) {
                $src = wp_get_attachment_image_src($thumb_id,'thumbnail');  //try thumbnail
                if (!$src) wp_get_attachment_image_src($thumb_id,'full');  //try full size
            }
            if ($src) {
                $imgstr = '<a href="'.$url.'"><img class="size-'.$size.' align'.$align.'" alt="" src="'.$src[0].'" width="'.$src[1].'" height="'.$src[2].'" /></a>';
                $details['post_content'] = $imgstr.$post_content;
                $details['ID'] = $post_id;
                wp_update_post($details);
            }
            
        }
    }
    //update the saved page with the post id
    $wpdb->update($ai_sl_pages_tbl, array('sl_post_id' => $post_id), array ('sl_page_id' => $page_id));
    mct_ai_log($topic['topic_name'],MCT_AI_LOG_ACTIVITY, 'New '.$post_msg.' post',$post_arr['current_link']);
}

function mct_ai_getpostcontent($page, &$post_arr, $type){
    //Grab the post content out of the rendered page
    global $mct_ai_optarray;
    $excerpt_length = $mct_ai_optarray['ai_excerpt'];
    $title = '';
    $article = '';
    //$page has the content, with html, using the format of rendered page, separate sections
    $cnt = preg_match('{<title>([^<]*)</title>}i',$page,$matches);
    if ($cnt) $title = $matches[1];
    $cnt = preg_match('{<span class="mct-ai-article-content">(.*)}si',$page,$matches);  //don't stop at end of line
    if ($cnt) $article = $matches[1];
    //Get rid of tags in title
    $title = preg_replace('{<([^>]*)>}',' ',$title);  //remove tags but leave spaces
    $post_arr['title'] = $title;  //save title 
    // Get original URL
    $pos = preg_match('{<div id="source-url">([^>]*)>([^<]*)<}',$page,$matches);
    if (isset($mct_ai_optarray['ai_new_tab']) && $mct_ai_optarray['ai_new_tab'] ) {
        $post_arr['orig_link'] = $matches[1].' target="_blank">'.$matches[2].'</a>';
    } else {
        $post_arr['orig_link'] = $matches[1].'>'.$matches[2].'</a>';
    }
    //Now get article content
    if ($type == 'Video' && !empty($mct_ai_optarray['ai_embed_video'])) {
        //Embed the iframe into article
        $pos = preg_match('{<iframe title="Video Player"[^>]*>}',$page,$matches);
        if ($pos) {
            $post_arr['article'] = $matches[0]."</iframe><br />"; //embed the iframe
            $post_arr['article'] = str_replace('width="250"', 'width="'.$mct_ai_optarray['ai_video_width'].'"',$post_arr['article']);
            $post_arr['article'] = str_replace('height="250"', 'height="'.$mct_ai_optarray['ai_video_height'].'"',$post_arr['article']);
            //try to get rid of any autoplay tags
            $pos = preg_match('{src="([^"]*)"}',$post_arr['article'],$matches);
            if ($pos){
                $pos = stripos($matches[1],'autoplay'); //match on lowercase
                if ($pos){
                    $qstr = substr($matches[1],$pos,8);//Not sure what is capitalized, so get original
                    $newstr = remove_query_arg($qstr,$matches[1]);
                    $post_arr['article'] = preg_replace('{(src=")([^"]*)(")}','$1'.$newstr.'$3',$post_arr['article']);
                }
            }
            /*Try to get youtube thumbnail
            if (preg_match('{youtube.com/(v|embed)/([^"|\?]*)("|\?)}i', $post_arr['article'], $match)) {
                $video_id = $match[2];
                $post_arr['yt_thumb'] = "http://img.youtube.com/vi/$video_id/2.jpg";
            }  */
            return;
        }
    }
    $article = preg_replace('@<style[^>]*>[^<]*</style>@i','',$article);  //remove style tags
    $article = preg_replace('{<([^>]*)>}',' ',$article);  //remove tags but leave spaces
    //$article = preg_replace('{&[a-z]*;}',"'",$article);  //remove any encoding
    //Save article snippet
    $excerpt = preg_replace('/\s+/', ' ', $article);  //get rid of extra spaces
    //Get Excerpt words
    if (!$excerpt_length) {
        $post_arr['article'] = '';
        return;  //no excerpt if set to 0
    }
    $words = explode(' ', $excerpt, $excerpt_length + 1);
    if ( count($words) > $excerpt_length ) {
            array_pop($words);
            array_push($words, '[...]');
            $excerpt = implode(' ', $words);
    }
    if (isset($mct_ai_optarray['ai_no_quotes']) && $mct_ai_optarray['ai_no_quotes'] ) {
        $post_arr['article'] = '<p id="mct_ai_excerpt">'.$excerpt.'</p>';  //save article
    } else {
        $post_arr['article'] = '<blockquote id="mct_ai_excerpt">'.$excerpt.'</blockquote>';  //save article
    }
    
}

function mct_ai_formatlink($post_arr){
    //Format link based on options
    global $mct_ai_optarray;
    
    $link = $post_arr['orig_link'];
    $title = $post_arr['title'];
    $intro = "Click here to view original web page at ";
    $cnt = preg_match('{^(<a[^>]*>)([^<]*)(</a>)$}',$link,$matches);
    $anchor = str_replace($intro,"",$matches[2]);
    
    if (isset($mct_ai_optarray['ai_post_title']) && $mct_ai_optarray['ai_post_title'] ){
        $anchor = $title;
    }
    if (isset($mct_ai_optarray['ai_no_anchor']) && $mct_ai_optarray['ai_no_anchor'] ){
        return $intro.$matches[1].$anchor."</a>";
    } else {
        return $matches[1].$intro.$anchor."</a>";
    }
    return $link;
}

function mct_ai_clean_postsread($pread){
    //Cleans up the log table, old target post types and the postsread table if posts are old (and $pread is true)
    global $ai_postsread_tbl, $mct_ai_optarray, $wpdb, $ai_logs_tbl;
    
    if ($pread){
        $feed_back = MCT_AI_OLDPOSTSREAD;
        //If not updated since log_days, get rid of entries
        $sql = "DELETE FROM $ai_postsread_tbl
                WHERE pr_date < ADDDATE(NOW(),-".$feed_back.")";
        $pr_row = $wpdb->query($sql);
        if (!empty($pr_row)){
            mct_ai_log('Posts Read',MCT_AI_LOG_PROCESS, 'Deleted '.$pr_row, '');
        }
    }
    
    $back = $mct_ai_optarray['ai_log_days'];
    //clean ai_log of errors/activities
    $sql = "DELETE FROM $ai_logs_tbl
            WHERE logs_date < ADDDATE(NOW(),-".$back.")";
    $pr_row = $wpdb->query($sql);
    if (!empty($pr_row)){
        mct_ai_log('Log',MCT_AI_LOG_PROCESS, 'Deleted '.$pr_row, '');
    }
    
    $back = $mct_ai_optarray['ai_train_days'];
    //clean out old training targets, use wp_delete_post which will trigger our hook to delete the saved page
    $postfile = $wpdb->posts;
    $sql = "SELECT ID FROM $postfile WHERE post_type = 'target_ai' AND post_date < ADDDATE(NOW(),-".$back.")";
    $cols = $wpdb->get_col($sql);
    if (!empty($cols)){
        foreach ($cols as $postid){
            wp_delete_post($postid);
        }
        mct_ai_log('Targets',MCT_AI_LOG_PROCESS, 'Deleted '.count($cols), '');
    }
}


function mct_ai_set_simplepie($args){
    //Set the cache duration
    $feed = $args;
    $feed->set_cache_duration(MCT_AI_PIE_CACHE);
}

?>