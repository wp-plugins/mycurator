<?php
/* MyCurator_fcns.php
 * Perform various support functions for MyCurator
 *
*/


function mct_ai_log($topic, $type, $msg, $url){
    //This function creates a log entry from the passed in values
    global $wpdb, $ai_logs_tbl;
    
    $ins_array = array(
                'logs_date' => current_time('mysql'),
                'logs_topic' => $topic,
                'logs_type' => $type,
                'logs_url' => $url,
                'logs_msg' => $msg
            );
    $wpdb->insert($ai_logs_tbl, $ins_array);
    
}

function mct_ai_postlink($args){
    //Post a link to db
    //Requires specific $arg fields which have been validated and cleansed already
    global $current_user;
    
    $cats = array (
        'orderby' => 'name',
        'hide_empty' => FALSE,
        'name' => 'link_category',
        'taxonomy' => 'link_category'
    );
    $link_category = $args['link_category'];
    $newlinkcat = $args['newlinkcat'];
    if (!empty($newlinkcat)){
        $theterm = wp_insert_term($newlinkcat,'link_category');
        if (is_wp_error($theterm)){
            return $theterm->get_error_message();
        } else {
            $link_category = $theterm['term_id'];
        }
    }
    //$current_user = wp_get_current_user();  //Owner of this link
    //Create the new Link Record
    $linkdata = array(
 	"link_url"		=> $args['save-url'], // Domain of 
	"link_name"		=> $args['feed_name'], // varchar, the title of the link
	"link_visible"		=> 'Y', // varchar, Y means visible, anything else means not
	"link_owner"		=> $current_user->ID, // integer, a user ID
	"link_rss"		=> $args['rss-url'], // varchar, a URL of an associated RSS feed
	"link_category"		=> $link_category // int, the term ID of the link category. 
    );
    $linkval = wp_insert_link( $linkdata, true );
    if (is_wp_error($linkval)){
        return $linkval->get_error_message();
    }
    return '';
}

function mct_ai_get_trainpage(){
    //returns a post object of type page for the training page on the site
    $pages = get_pages(array('post_status' => 'publish,private'));
    foreach ($pages as $page) {
        if (stripos($page->post_content,"MyCurator_training_page") !== false) {
            return $page;
        }
    }
    return '';
}
function mct_ai_tw_expandurl($url){
    //Expand the passed in twitter url and return it - uses just the header, no body  
    $ch = curl_init($url);
    curl_setopt($ch,CURLOPT_HEADER,true);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_NOBODY,true);    
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,true);
    $headers = curl_exec($ch);
    curl_close($ch);
    if (preg_match_all('/Location:\s(.+?\s)/i', $headers, $matches)) {
        $pos = count($matches[1]) - 1;
        $url = trim($matches[1][$pos]);
        //Strip off the query and fragments
        $parsed_url = parse_url($url);
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : ''; 
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : ''; 
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : ''; 
        //mct_ai_testurl("$scheme$host$path");
        return "$scheme$host$path";
    } else {
        return "";
    }
    return $url;
}

function mct_ai_testurl($url){
    $ch = curl_init($url);
    curl_setopt($ch,CURLOPT_HEADER,true);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_NOBODY,true);    
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,true);
    $headers = curl_exec($ch);
    $curlinfo = curl_getinfo($ch);
    curl_close($ch);
    if ($curlinfo['http_code'] != 200) {
        mct_ai_log("URL Test Error",MCT_AI_LOG_ACTIVITY, 'HTTP error: '.strval($curlinfo['http_code']),$url);
        return false;
    } 
}

function mct_ai_postthumb($imgurl, $post_id, $title) {
    // Load an image pointed to by a url into the post thumbnail featured image
    // Adapted from code by Aditya Mooley from auto post thumbnail plugin
    

    // Get the file name
    $filename = substr($imgurl, (strrpos($imgurl, '/'))+1);
    //weed out %dd in filename
    $filename = preg_replace('{%\d\d}','-',$filename);
    if (!(($uploads = wp_upload_dir(current_time('mysql')) ) && false === $uploads['error'])) {
        return null;
    }

    // Generate unique file name
    $filename = wp_unique_filename( $uploads['path'], $filename );

    // Move the file to the uploads dir
    $new_file = $uploads['path'] . "/$filename";
    
    if (!ini_get('allow_url_fopen')) {
        $file_data = mct_ai_curl_get_file_contents($imgurl);
    } else {
        $file_data = @file_get_contents($imgurl);
    }
    
    if (!$file_data) {
        return null;
    }
    
    file_put_contents($new_file, $file_data);

    // Set correct file permissions
    $stat = stat( dirname( $new_file ));
    $perms = $stat['mode'] & 0000666;
    @ chmod( $new_file, $perms );

    // Get the file type. Must to use it as a post thumbnail.
    $wp_filetype = wp_check_filetype( $filename );

    extract( $wp_filetype );

    // No file type! No point to proceed further
    if ( ( !$type || !$ext ) && !current_user_can( 'unfiltered_upload' ) ) {
        return null;
    }

    // Compute the URL
    $url = $uploads['url'] . "/$filename";

    // Construct the attachment array
    $attachment = array(
        'post_mime_type' => $type,
        'guid' => $url,
        'post_parent' => null,
        'post_title' => $title,
        'post_content' => '',
    );

    $thumb_id = wp_insert_attachment($attachment, false, $post_id);
    if ( !is_wp_error($thumb_id) ) {
        require_once(ABSPATH . '/wp-admin/includes/image.php');
        
        wp_update_attachment_metadata( $thumb_id, wp_generate_attachment_metadata( $thumb_id, $new_file ) );
        update_post_meta($thumb_id,'_wp_attachment_image_alt',$title); //Add title as alt
        update_attached_file( $thumb_id, $new_file );
        return $thumb_id;
    }

    return null;
}

/**
 * Function to fetch the contents of URL using curl in absense of allow_url_fopen.
 * 
 * Copied from user comment on php.net (http://in.php.net/manual/en/function.file-get-contents.php#82255)
 */
function mct_ai_curl_get_file_contents($URL) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    curl_close($c);

    if ($contents) {
        return $contents;
    }
    
    return FALSE;
}

function mct_ai_train_ajax() {
    //Handle ajax requests from training pages/posts
    global $mct_ai_optarray;
    
    $response = new WP_Ajax_Response;
    //User Cap ok?
    if (!current_user_can('edit_published_posts')){
        $response->add(array('data' => 'Error - Not Allowed'));
        $response->send();
        exit();
    }
    //Get args
    $args = wp_parse_args($_POST['qargs']);
    //Multi class set
    if (!empty($args['multi'])){ 
        $pid = intval($args['multi']);
        if (!check_ajax_referer('mct_ai_multi'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        $terms = mct_ai_train_multi($pid);
        if ( is_wp_error($terms) ) { 
            $response->add(array('data' => 'Error - Update Term'));
        } else {
            $response->add(array(
                'data' => 'Ok',
                'supplemental' => array(
                    'action' => 'multi',
                    'remove' => 0
                ),
            ));
        }
        $response->send();
        exit();
    }
    //Delete post
    if (!empty($args['action']) && $args['action'] == 'trash'){ 
        $pid = intval($args['post']);
        if (!check_ajax_referer('trash-post_'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        $terms = wp_trash_post($pid);
        if ( !$terms ) { 
            $response->add(array('data' => 'Error - Could Not Delete'));
        } else {
            $response->add(array(
                'data' => 'Ok',
                'supplemental' => array(
                    'action' => 'delete',
                    'remove' => $pid
                ),
            ));
        }
        $response->send();
        exit();
    }
    //Train Bad
    if (!empty($args['bad'])){ 
        $pid = intval($args['bad']);
        if (!check_ajax_referer('mct_ai_train_bad'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        $tname = mct_ai_get_tname_ai($pid);
        if ($tname != ''){
            mct_ai_trainpost($pid, $tname, 'bad'); 
            $terms = wp_trash_post($pid);
            if ( !$terms ) { 
                $response->add(array('data' => 'Error - Could Not Delete Post'));
            } else {
                $response->add(array(
                    'data' => 'Ok',
                    'supplemental' => array(
                        'action' => 'bad',
                        'remove' => $pid
                    ),
                ));
            }
        } else {
            $response->add(array('data' => 'Error - Could not find Topic'));
        } 
        $response->send();
        exit();
    }
    //Train Good
    if (!empty($args['good'])){ 
        $pid = intval($args['good']);
        if (!check_ajax_referer('mct_ai_train_good'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        $tname = mct_ai_get_tname_ai($pid);
        if ($tname != ''){
            mct_ai_trainpost($pid, $tname, 'good'); 
            if (!empty($mct_ai_optarray['ai_keep_good_here'])) {
                $remove = 0;
                $edit = 'no';
            } else {
                $remove = $pid;
                if (!empty($mct_ai_optarray['ai_edit_makelive'])) {
                    mct_ai_traintoblog($pid,'draft');
                    $edit = 'yes';
                } else {
                    mct_ai_traintoblog($pid,'publish');
                    $edit = 'no';
                }
            }
            
            $response->add(array(
                    'data' => 'Ok',
                    'supplemental' => array(
                        'action' => 'good',
                        'edit' => $edit,
                        'remove' => $remove
                    ),
                ));
        } else {
            $response->add(array('data' => 'Error - Could not find Topic'));
        } 
        $response->send();
        exit();
    }
    //Make Live
    if (!empty($args['move'])){ 
        $pid = intval($args['move']);
        if (!check_ajax_referer('mct_ai_move'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        if (!empty($mct_ai_optarray['ai_edit_makelive'])) {
            mct_ai_traintoblog($pid,'draft');
            $edit = 'yes';
        } else {
            mct_ai_traintoblog($pid,'publish');
            $edit = 'no';
        }
        $response->add(array(
                'data' => 'Ok',
                'supplemental' => array(
                    'action' => 'move',
                    'edit' => $edit,
                    'remove' => $pid
                ),
        ));
        $response->send();
        exit();
    }
    //draft
    if (!empty($args['draft'])){ 
        $pid = intval($args['draft']);
        if (!check_ajax_referer('mct_ai_draft'.$pid,'nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        mct_ai_traintoblog($pid,'draft');
        $edit = 'no';
        $response->add(array(
                'data' => 'Ok',
                'supplemental' => array(
                    'action' => 'draft',
                    'edit' => 'no',
                    'remove' => $pid
                ),
        ));
        $response->send();
        exit();
    }
    //Quick Post
    if (!empty($args['quick'])){ 
        $pid = intval($args['quick']);
        if (!check_ajax_referer('bulk-posts','nonce', false)) {
            $response->add(array('data' => 'Error - Bad Nonce'));
            $response->send();
            exit();
        }
        //rebuild the post contents
        $thepost = get_post($pid, ARRAY_A);
        if (empty($thepost)) {
            $response->add(array('data' => 'Error - No Post - '.$pid));
            $response->send();
            exit();
        }
        $newpost = array();
        $newpost['ID'] = $pid;
        $newpost['post_title'] = trim(sanitize_text_field($_POST['title']));
        $excerpt = trim(sanitize_text_field($_POST['excerpt']));
        $notes = trim(sanitize_text_field($_POST['note']));
        $type = trim(sanitize_text_field($_POST['type']));
        $content = $thepost['post_content'];
        //new text
        $newtext = '';
        if (!empty($notes)) $newtext = '<p>'.$notes.'</p>';
        if (!empty($excerpt)){
            if (empty($mct_ai_optarray['ai_no_quotes'])) {
                $newtext = $newtext.'<blockquote id="mct_ai_excerpt">'.$excerpt.'</blockquote>';
            } else {
                $newtext = $newtext.'<p id="mct_ai_excerpt">'.$excerpt.'</p>';
            }
        }
        //place in content
        if (stripos($content,'<blockquote id="mct_ai_excerpt">') !== false) {
            $content = preg_replace('{<blockquote id="mct_ai_excerpt">(<p>)?([^<]*)(</p>)?</blockquote>}',$newtext,$content);
        } elseif (stripos($content,'<p id="mct_ai_excerpt">') !== false) {
            $content = preg_replace('{<p id="mct_ai_excerpt">([^<]*)</p>}',$newtext,$content);
        } elseif (stripos($content, '<p id="mct-ai-attriblink">') !== false) {
            //place just in front of link
            $pos = stripos($content, '<p id="mct-ai-attriblink">');
            if ($pos == 0) {
                $content = $newtext.$content;
            } else {
                $content = substr($content,0,$pos).$newtext.substr($content,$pos);
            }
        } else {
            //just append to the end, probably a picture or a video
            $content = $content.$newtext;
        }
        $newpost['post_content'] = $content;
        wp_update_post($newpost);
        //Now move it out of training
        mct_ai_traintoblog($pid, $type);
        $response->add(array('data' => 'Ok'));
        $response->send();
        exit();
    }
    exit();
}

function mct_ai_get_tname_ai($post_id){
    //Returns the topic name for a post if it is a Relevance type, else blank
    global $wpdb, $ai_topic_tbl;
    
    $terms = get_the_terms( $post_id, 'topic' );
    $tname = '';
    if (count($terms) == 1 ) { //should only be one
        //The array key is the id
        $tids = array_keys($terms);
        $term = $terms[$tids[0]];
        $tname = $term->name;
    } else {
        return '';
    }
    // Check whether Relevance type 
    $sql = "SELECT `topic_type`
            FROM $ai_topic_tbl
            WHERE topic_name = '$tname'";
     $edit_vals = $wpdb->get_row($sql, ARRAY_A);
     if ($edit_vals['topic_type'] != "Relevance") return '';
     
    return $tname;
}

function mct_ai_traintoblog($thepost, $status){
    global $wpdb, $ai_topic_tbl, $mct_ai_optarray;
    
    //Move a post - change post type from target_ai to post
    //read topic table for this category/tag
    $topic = wp_get_object_terms($thepost,'topic',array('fields' => 'names'));
    if (!empty($topic)) {
        $tname = $topic[0];  //should always only be one
        $sql = "SELECT topic_cat, topic_tag, topic_tag_search2 FROM $ai_topic_tbl WHERE topic_name = '$tname'";
        $row = $wpdb->get_row($sql);
        if (!empty($row)){
            $details = array();
            $details['ID'] = $thepost;
            $details['post_category'] = array($row->topic_cat);
            if ($row->topic_tag_search2){
                $details['tags_input'] = get_post_meta($thepost,'mct_ai_tag_search2', true);
            } else {
                $tagterm = get_term($row->topic_tag,'post_tag');
                $details['tags_input'] = array($tagterm->name);
            }
            $details['post_type'] = 'post';
            if ($status == 'draft') {
                $details['post_status'] = 'draft';
                if (!empty($mct_ai_optarray['ai_now_date'])) {
                    $details['edit_date'] = true;
                    $details['post_date'] = '';
                    $details['post_date_gmt'] = "0000-00-00 00:00:00";
                }
            } else {
                if (!empty($mct_ai_optarray['ai_now_date'])) {
                    $details['edit_date'] = true;
                    $details['post_date'] = '';
                    $details['post_date_gmt'] = '';
                }
            }
            wp_update_post($details);
        }
    }
}

function mct_ai_train_multi($pid){
    //Set multi ai_class
   return wp_set_object_terms($pid,'multi','ai_class',false);
}
?>
