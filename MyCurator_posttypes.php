<?php
/*MyCurator_posttypes
*These functions register the post type, use other hooks for special manipulation, use shortcodes to display the custom post type
* on pages
*
* Version 1.0
*/ 
//Register post type and taxonomies on init
add_action('init','mct_ai_register');
//manage columns on new post type
add_filter( "manage_target_ai_posts_columns", "target_ai_chg_col" );
add_action( "manage_posts_custom_column", "target_ai_custom_col", 10, 2 );
add_action( 'restrict_manage_posts', 'target_ai_restrict_manage_posts' );
add_filter( 'parse_query', 'target_ai_filter_post_type_request' );
//Metabox to show link data  for target_ai posttype- 
add_action('add_meta_boxes','mct_ai_linkmeta');
//Create the News/Twitter Feed on the Links menu
add_action('admin_menu', 'bwc_add_link_alerts');
//Metabox to show relevance data
add_action('add_meta_boxes','mct_ai_relmeta');  //for posts
add_action('add_meta_boxes','mct_ai_relmetatarget'); //For targets
//the content filter to add training links
add_filter('the_content', 'mct_ai_traintags', 20);
add_filter('the_excerpt', 'mct_ai_traintags', 20);

function mct_ai_register(){
    //Registers custom post type targets
    //Set up args array
    $target_args = array (
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'query_var' => 'target',
        'rewrite' => array ('slug' => 'target'),
        'supports' => array( 
            'title', 'editor'
        ),
        'labels' => array(
            'name' => 'Training Posts',
            'singular_name' => 'Training Post',
            'add_new' => 'Add New Training Post',
            'add_new_item' => 'Add New Training Post',
            'edit_item' => 'Edit Training Post',
            'new_item' => 'New Training Post',
            'view_item' => 'View Training Post',
            'search_items' => 'Search Training Posts',
            'not_found' => 'No Training Posts Found',
            'not_found_in_trash' => 'No Training Posts Found In Trash'
        ),
    );
   
    register_post_type('target_ai',$target_args);
    
    //Set up taxonomy Topics - this will be populated when we create topics
    //Shouldn't be touched by users, so set capability to super admin 
    $topic_args = array (
        'public' => false,
        'show_ui' => true,
        'show_tagcloud' => false,
        'query_var' => true,
        'rewrite' => array ('slug' => 'topic'),
        'labels' => array(
            'name' => 'Topics',
            'singular_name' => 'Topic',
            'add_new_item' => 'Add New Topic',
            'update_item' => 'Update Topic',
            'edit_item' => 'Edit Topic',
            'new_item_name' => 'New Topic',
            'all_items' => 'All Topics',
            'search_items' => 'Search Topics',
            'popular_items' => 'Popular Topics',
            'choose_from_most_used' => 'Choose from most popular Topics',
            'separate_items_with_commas' => 'Separate Topics with commas'
        ),
        'capabilities' => array (
            'manage_terms' => 'manage_network', //by default only super admin - shouldn't be available
            'edit_terms' => 'manage_network',
            'delete_terms' => 'manage_network',
            'assign_terms' => 'edit_others_posts'  
        ),
    );
    register_taxonomy('topic', array('target_ai'), $topic_args);
    
    //Set up taxonomy ai_class - this will be populated up front
    //Shouldn't be touched by users, so set capability to super admin
    $class_args = array (
        'public' => false,
        'show_ui' => true,
        'show_tagcloud' => false,
        'query_var' => true,
        'rewrite' => array ('slug' => 'ai_class'),
        'labels' => array(
            'name' => 'Relevance',
            'singular_name' => 'Relevance',
            'add_new_item' => 'Add New Relevance',
            'update_item' => 'Update Relevance',
            'edit_item' => 'Edit Relevance',
            'new_item_name' => 'New Relevance',
            'all_items' => 'All Relevance',
            'search_items' => 'Search Relevance',
            'popular_items' => 'Popular Relevance',
            'choose_from_most_used' => 'Choose from most popular Relevance',
            'separate_items_with_commas' => 'Separate Relevance with commas'
        ),
        'capabilities' => array (
            'manage_terms' => 'manage_network', //by default only super admin - shouldn't be available
            'edit_terms' => 'manage_network',
            'delete_terms' => 'manage_network',
            'assign_terms' => 'edit_others_posts'  
        ),
    );
    register_taxonomy('ai_class', array('target_ai'), $class_args);
    
    //Shortcode for a target_ai posts page
    add_shortcode('MyCurator_training_page','target_ai_shortcode');
    
}

// Change the columns for the edit CPT screen
function target_ai_chg_col( $cols ) {

    $cols['expt'] = 'Excerpt';
    $cols['topic'] = 'Topic';
    $cols['class'] = 'Relevance';
    $cols['origurl'] = 'Original URL';
    $cols['newurl'] = 'New URL';
    

  return $cols;
}

function target_ai_custom_col( $column, $post_id ) {
    //Output custom columns for new post type
  $origlinks = get_post_meta($post_id,'mct_sl_origurl',true);
  $newlinks = get_post_meta($post_id,'mct_sl_newurl',true);
  switch ( $column ) {
    case "topic":
      $terms = get_the_terms( $post_id, 'topic');
      if (!empty($terms)) {
          foreach ($terms as $term){
              echo $term->name;
          }
      }
      break;
    case "class":
      $terms = get_the_terms( $post_id, 'ai_class');
      if (!empty($terms)) {
          foreach ($terms as $term){
              echo $term->name;
          }
      }
      break;
    case "origurl":
      if (!empty($origlinks)){
          echo '<a href="'.$origlinks[0].'">Original Page</a>';
      }
      break;
    case "newurl":
      if (!empty($newlinks)){
          echo '<a href="'.$newlinks[0].'">Readable Page</a>';
      }
      break;
    case "expt":
      $val = wp_strip_all_tags(get_the_content(),true);
      if (strlen($val) > 100){
          $val = substr($val,0,100);
      }
      echo $val;
      
      break;
  }
}

// Filter the request to just give posts for the given taxonomy, if applicable.
function target_ai_restrict_manage_posts() {
    global $typenow;

    // If you only want this to work for your specific post type,
    // check for that $type here and then return.
    // This function, if unmodified, will add the dropdown for each
    // post type / taxonomy combination.

    if ($typenow != 'target_ai') {
        return;
    }

    $filters = get_object_taxonomies( $typenow );

        foreach ( $filters as $tax_slug ) {
            $tax_obj = get_taxonomy( $tax_slug );
            wp_dropdown_categories( array(
                'show_option_all' => __('Show All '.$tax_obj->label ),
                'taxonomy' 	  => $tax_slug,
                'name' 		  => $tax_obj->name,
                'orderby' 	  => 'name',
                'hierarchical' 	  => $tax_obj->hierarchical,
                'show_count' 	  => false,
                'hide_empty' 	  => true
            ) );
        }
}

function target_ai_filter_post_type_request( $query ) {
    //Uses the slug for the filter
  global $pagenow, $typenow;

  if ( 'edit.php' == $pagenow ) {
    $filters = get_object_taxonomies( $typenow );
    foreach ( $filters as $tax_slug ) {
      $var = &$query->query_vars[$tax_slug];
      if ( isset( $var ) ) {
        $term = get_term_by( 'id', $var, $tax_slug );
        $var = $term->slug;
      }
    }
  }
}

function target_ai_shortcode(){
    //Displays target_ai post types on a page with this shortcode
    //Very little formatting so it will pick up css from current theme
    
    global $post, $ai_topic_tbl, $wpdb, $wp_query, $paged, $blog_id;
    $qtopic = '';
    $qaiclass = '';
    $msg = '';

    //handle get requests for topic and ai_class, ai_class is nested in previous topic
    if (isset($_GET['topic'])){
        $qtopic = $_GET['topic'];
        set_transient('mct_ai_lasttopic',$qtopic,60*60);
    }
    elseif (isset($_GET['ai_class'])){
        $qaiclass = $_GET['ai_class'];
    }
    else {
        set_transient('mct_ai_lasttopic','');
    }
    
    //Set up query with paging
    $q_args = array(
                'post_type' => 'target_ai',
                'orderby' => 'date',
                'order' => 'DESC',
                'posts_per_page' => 10,
                'paged' => $paged
            );
    if (!empty($qtopic)){
        $q_args['topic'] = $qtopic;
        $qterm = get_term_by('slug',$qtopic,'topic');
        $msg = "Topic &raquo; ".$qterm->name;
    }
    if (!empty($qaiclass)){
        $lasttopic=get_transient('mct_ai_lasttopic');
        if (!empty($lasttopic)){
            $qterm = get_term_by('slug',$lasttopic,'topic');
            $q_args['topic'] = $lasttopic;
            $q_args['ai_class'] = $qaiclass;
            $msg = "Topic &raquo; ".$qterm->name." &middot Relevance &raquo; ".$qaiclass;
        } else {
            $q_args['ai_class'] = $qaiclass;
            $msg = "<em>Showing Relevance: ".$qaiclass."</em>";
        }
    }

    //display filter links
    mct_ai_train_nav();

    $temp = clone $wp_query;
   
    $wp_query = new WP_Query($q_args);

     //get_header();
     if (have_posts()){
         if (!empty($msg)){
             echo $msg;
         }
         while (have_posts()) {
             the_post();
?>             
<!-- post title -->
<div <?php post_class('fpost') ?> id="post-<?php the_ID(); ?>">
       <?php //Set link to saved page on title
    $pages = mct_ai_getsavedpageid($post->ID);
    $page_id = $pages[0];
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
    ?>
          <h2><?php echo '<a href="'.$link_redir.'" >'.get_the_title().'</a>'; ?></h2>
            <?php echo(get_the_date()); echo ('&nbsp;&middot&nbsp;'); 
            edit_post_link( '[Edit]', '', '');

            echo ('&nbsp;&middot&nbsp;');
            echo(get_the_term_target_ai($post->ID,'topic','Topic: ',',',' ')); echo ('&nbsp;&middot&nbsp;'); 
            echo(get_the_term_target_ai($post->ID,'ai_class','Relevance: ',',',' ')); ?><br />
<!-- Content -->
<br />
           <?php if ( has_post_thumbnail() ) the_post_thumbnail('thumbnail'); ?>
            <?php 
                    the_content();
              ?>
</div>

<?php         }  //end while   ?>
    <div class="page-nav">
	    <div class="nav-previous"><?php previous_posts_link(__('&larr; Previous Page')) ?></div>
	    <div class="nav-next"><?php next_posts_link(__('Next Page &rarr;')); ?></div>
    </div>  
 

  <?php   } else {
         echo '<h2>No Training Posts Found</h2>';
     }
     $wp_query = clone $temp;
     return ;
    
}

function get_the_term_target_ai($postid, $taxname, $before, $sep, $after){
    //Get terms for this target post
    $terms = get_the_terms( $postid, $taxname );
    //Get uri, strip out previous gets, any page info
    
    $uri = $_SERVER['REQUEST_URI'];
    $uri = remove_query_arg(array('topic', 'ai_class', 'move', 'good', 'bad'),$uri);
    $uri = preg_replace('{/page/[^/]*/}i','/', $uri);
    if ( $terms && ! is_wp_error( $terms ) ) { 

	$term_links = array();

	foreach ( $terms as $term ) {
		$term_links[] = '<a href="'.esc_url($uri.'?'.$taxname.'='.$term->slug).'">'.$term->name.'</a>';
	}
	$full_str = $before.join( $sep, $term_links ).$after;				
	return $full_str;
    }
}

function mct_ai_train_nav(){
    //Sets up the navigation at the top of the page
    //Get uri, strip out previous gets
    $uri = $_SERVER['REQUEST_URI'];
    $pos = stripos($uri, '?');
    if ($pos !== false){
        $uri = substr($uri,0,$pos);
    }
    //strip out paging so we are back to page 1
    $uri = preg_replace('{/page/[^/]*/}i','/', $uri);
    //Display title
    echo '<div class mct_ai_train_nav>';
    echo "<strong>Select Targets to View</strong><br />";
    echo '<em>TOPICS: </em>';
    // Get the topic terms, set as links and list
    $taxname = 'topic';
    $terms = get_terms($taxname);
    foreach ($terms as $term) {
        echo '&middot;<a href="'.esc_url($uri.'?'.$taxname.'='.$term->slug).'">'.$term->name.'</a>';
    }
    //
    echo '<br />';
    echo '<em>RELEVANCE: </em>';
    //Get the ai-class links and list
    $taxname = 'ai_class';
    $terms = get_terms($taxname);
    foreach ($terms as $term) {
        echo '&middot;<a href="'.esc_url($uri.'?'.$taxname.'='.$term->slug).'">'.$term->name.'</a>';
    }
    echo '</div>';
}

function mct_ai_traintags($content){
    //filter on the_content - add training tags
    // if single, display full text on post
    
    if (is_single()) {
        //Get the article link, should only be one if MyCurator posted this
        $cnt = preg_match_all('{<a\s(.*)/ailink/[0-9]+"\s*>}',$content,$matches);
        if ($cnt == 1) {
            $linktxt = $matches[0][0]; 
            $pos = preg_match('{/ailink/([0-9]+)"\s*>}',$linktxt,$matches);
             if ($pos) {
                 $page_id = intval($matches[1]);
                 $vals = mct_sl_getsavedpage($page_id);
                 $page = $vals['sl_page_content'];
                 $cnt = preg_match('{<span class="mct-ai-article-content">(.*)}si',$page,$matches);  //don't stop at end of line
                 $article = $matches[1];
                 $article = preg_replace('{</span></div></body></html>}','',$article);
                 $pos = preg_match('{<div id="source-url">([^>]*)>([^<]*)<}',$page,$matches);
                 //Check for MyCurator auto-post excerpt
                 if (stripos($content,'<blockquote id="mct_ai_excerpt">') !== false) {
                     //Put in Source URL
                     $content = $matches[1].'> '.$matches[2].'</a>'.$content;
                     //Replace the excerpt with the full article
                     $content = preg_replace('{<blockquote id="mct_ai_excerpt">(<p>)?([^<]*)(</p>)?</blockquote>}',"<br />".$article,$content);
                     
                 } else {
                     //Put in Source URL
                     $content = $matches[1].'> '.$matches[2].'</a>'.$content;
                     //Keep the content as it has been changed from simple excerpt
                     //Place the article in front of the link
                     $pos = stripos($content,$linktxt);
                     $content = substr($content,0,$pos)."<br />".$article."<br />".substr($content,$pos);
                 }
            }
        }
    }
    $trainstr =  mct_ai_addtrain();
    if (!empty($trainstr)) {
        //put trainstr next to link, 
        $pos = preg_match('{/ailink/([0-9]+)"\s*>([^<]*)</a>}',$content,$matches);
        if ($pos) {
            $pos = stripos($content,$matches[0]);
            $len = strlen($matches[0]);
            $content = substr($content,0,$pos+$len).$trainstr."<br />".substr($content,$pos+$len);
        } else {
            $pos = stripos($content, "</a></p>");
            if ($pos) {  //place in final <p> if found since MyCurator puts in just one link
                $len = strlen($content);
                $content = substr($content,0,$pos+4)."&nbsp;".$trainstr.substr($content,$pos+4);
            } else {
                $content .= "&nbsp;".$trainstr;
            }
        }
    }
    return $content;
    
}

function is_trainee($postid){
    //Checks if this is a trainable post built by MyCurator
    global $wpdb, $ai_topic_tbl;
    
    // Get the topic name
    $terms = get_the_terms( $postid, 'topic' );
    if (count($terms) != 1 || $terms === false) return 'No';  //should only be one
    //The array key is the id
    $tids = array_keys($terms);
    $term = $terms[$tids[0]];
    $tname = $term->name;
    
    // Check whether Relevance type 
    $sql = "SELECT `topic_type`
            FROM $ai_topic_tbl
            WHERE topic_name = '$tname'";
     $edit_vals = $wpdb->get_row($sql, ARRAY_A);
     if ($edit_vals['topic_type'] != "Relevance") return 'No';
     
     // Check whether we have just one link
     if (count(mct_ai_getsavedpageid($postid)) != 1) return 'No';
     // Already trained for this topic?
     $train = get_post_meta($postid,'mct_ai_trained',true);
     if (empty($train)) return 'Yes';
     foreach($train as $tr){
         $pos = stripos($tr, $tname);
         if ($pos !== false) {
             return 'Trained '.substr($tr,0,$pos-1);
         }
     }
     return 'Yes';
}

function mct_ai_addtrain(){
    //This function sets the training keys and trash for training and live posts
    global $post, $mct_ai_optarray;
    //Does user have edit authority for this post
    $post_type_object = get_post_type_object( $post->post_type );
    if ( !$post_type_object )
            return '';

    if ( !current_user_can( $post_type_object->cap->edit_post, $post->ID ) )
            return '';
    //see if we have a topic taxonomy for this post
    // Get the topic name
    $terms = get_the_terms( $post->ID, 'topic' );
    if ($terms === false ) return '';  //should only be one
    //Is this a target post?
    $tgt = false;
    if ($post->post_type == 'target_ai'){
        $tgt = true;
    }
    //Is this post from MyCurator?
    $istrain = is_trainee($post->ID);
    if ($istrain == 'No') return '';
    
    // set up the training keys
    $retstr = '';
    $train_base = plugins_url('MyCurator_train.php',__FILE__);
    $imggood = plugins_url('thumbs_up.png',__FILE__);
    $imgbad = plugins_url('thumbs_down.png',__FILE__);
    $imgtrash = plugins_url('trash_icon.png', __FILE__);
    
    //Trained, but on training page, so just put out make live
    if ($istrain != 'Yes' && $tgt) {
        $retstr .= '&nbsp; <a class="mct-ai-link" href="'.get_delete_post_link($post->ID).'" ><img src="'.$imgtrash.'" ></img></a>';
        $move_uri = add_query_arg(array('move' => strval($post->ID)), $train_base);
        $move_uri = wp_nonce_url($move_uri, 'mct_ai_move'.$post->ID);
        $retstr .= '&nbsp; <a class="mct-ai-link" href="'.$move_uri.'" >[Make Live]</a>';
        return $retstr;
    }
    if ($istrain != 'Yes') return '';
    
    if ($tgt && !$mct_ai_optarray['ai_keep_good_here']){
        $train_uri = add_query_arg(array('good' => strval($post->ID), 'move' => strval($post->ID)), $train_base);
        $train_uri = wp_nonce_url($train_uri, 'mct_ai_train_good'.$post->ID);
        $retstr .= '&nbsp; <a class="mct-ai-link" href="'.$train_uri.'" ><img src="'.$imggood.'" ></img></a>'; 
    } else {
        $train_uri = add_query_arg(array('good' => strval($post->ID)), $train_base);
        $train_uri = wp_nonce_url($train_uri, 'mct_ai_train_good'.$post->ID);
        $retstr .= '&nbsp; <a class="mct-ai-link" href="'.$train_uri.'" ><img src="'.$imggood.'" ></img></a>'; 
    }
    $train_uri = add_query_arg(array('bad' => strval($post->ID)), $train_base);
    $train_uri = wp_nonce_url($train_uri, 'mct_ai_train_bad'.$post->ID);
    $retstr .= '&nbsp; <a class="mct-ai-link" href="'.$train_uri.'" ><img src="'.$imgbad.'" ></img></a>'; 
    //Set the trash key
    $retstr .= '&nbsp; <a class="mct-ai-link" href="'.get_delete_post_link($post->ID).'" ><img src="'.$imgtrash.'" ></img></a>';
    if ($tgt){
        $move_uri = add_query_arg(array('move' => strval($post->ID)), $train_base);
        $move_uri = wp_nonce_url($move_uri, 'mct_ai_move'.$post->ID);
        $retstr .= '&nbsp; <a class="mct-ai-link" href="'.$move_uri.'" >[Make Live]</a>';
    }
    return $retstr;
}

function mct_ai_relmeta(){

    //Get training page link
    $pages = get_pages(array('post_status' => 'publish,private'));
    foreach ($pages as $page) {
        if (stripos($page->post_content,"MyCurator_training_page") !== false) {
            $trainpage = get_page_link($page->ID);
            break;
        }
    }
    
    $trainpage = '<a href="'.$trainpage.'" />Link to MyCurator Training Page on your site</a>';
    add_meta_box('mct_ai_metabox','Relevance Data','mct_ai_relmetashow','post','normal','low');
    add_meta_box('mct_ai_slpage','Saved Page >>'.$trainpage,'mct_ai_showpage','post','normal','high');
}
function mct_ai_relmetatarget(){
    add_meta_box('mct_ai_metabox','Relevance Data','mct_ai_relmetashow','target_ai','normal','low');
}

function mct_ai_relmetashow($post){
    //Show the relevance data for a post in the meta box
    $reldata = get_post_meta($post->ID, 'mct_ai_relevance',true);
    if (empty($reldata)) return;
    
    echo 'Classed as '.strtoupper($reldata['classed']).' --> Good Score: '.$reldata['good'].' Bad Score: '.$reldata['bad'];
    if (!empty($reldata['dbsize'])){
        echo '  Features: '.$reldata['dbsize'];
    }
}

function mct_ai_showpage($post){
    //Display the saved page in a  meta box for use in curating post
    //Get saved page id
    $pages = mct_ai_getsavedpageid($post->ID);
    if (empty($pages)) return;
    $page = mct_ai_getsavedpage($pages[0]);
    //pull out the article text
    $cnt = preg_match('{<span class="mct-ai-article-content">(.*)}si',$page,$matches);  //don't stop at end of line
    $article = $matches[1];
    $article = preg_replace('{</span></div></body></html>}','',$article);
    //pull out any side images
    $images = '';
    $pos = stripos($page,'<div id="box_media">');
    if ($pos){
        $images = substr($page,$pos);
        $pos = stripos($images,'</div>');
        $images = substr($images,0,$pos+5);
    }
    echo $article;
    if ($images){
        //quick style
        echo "<style> #box_media {padding: 5px;} #box_media #side_image {padding: 5px;} </style>";
        echo "<h3>Images</h3>".$images;
    }
  
}

function mct_ai_linkmeta(){
    add_meta_box('mct_sl_metabox','Link Replacement for MyCurator','mct_sl_linkmetashow','target_ai','normal','low');
}

//Add the Create News/Twitter menu item
function bwc_add_link_alerts(){
    add_links_page('Create News Feed', 'News or Twitter', 'edit_posts', 'bwc_create_news', 'bwc_create_news');// Google News Feed
}

//Create News/Twitter Feed Screen
function bwc_create_news(){

    $msg = '';
    //Get Link Categories for dropdown
    $cats = array (
        'orderby' => 'name',
        'hide_empty' => FALSE,
        'name' => 'link_category',
        'taxonomy' => 'link_category'
    );
    $v = array(
            'aname' => '',
            'keywords' => '',
            'link_category' => '',
            'newlinkcat' => '' 
    );
    
    //Handle POST
    if (isset($_POST['Submit'])){
        check_admin_referer('mct_ai_newsfeed','tweetnews');
        //Validate Fields
        $v = array (
            'aname' => trim(sanitize_text_field($_POST['aname'])),
            'keywords' => trim(stripslashes($_POST['keywords'])),
            'link_category' => strval(absint($_POST['link_category'])),
            'newlinkcat' => trim(sanitize_text_field($_POST['newlinkcat']))
        );
        if ($_POST['ftype'] == 'G') {
            $feed_type = true;
        } else {
            $feed_type = false;
        }
        $cats['selected'] = $v['link_category'];
        $msg = '';
        if (empty($v['aname'])) $msg = bwc_add_msg($msg,'Must have a Feed Name');
        if (empty($v['keywords'])) $msg = bwc_add_msg($msg,'Must have Keywords');

        if (empty($msg)) {
        //Create the new category if entered
            if (!empty($v['newlinkcat'])){
                $theterm = wp_insert_term($v['newlinkcat'],'link_category');
                if (is_wp_error($theterm)){
                    $msg = $theterm->get_error_message();
                } else {
                    $v['link_category'] = $theterm['term_id'];
                }
            }
            if (empty($msg)){
                //If OK do the feed creation
                $ret = bwc_do_news_create($v,$feed_type);
                if (is_string($ret)) $msg = $ret;
            }
        }
        if (!empty($msg)) {
            $msgclass = 'error';
        } else {
            if ($feed_type) {
                $msg = 'Google News Feed Created';
            } else {
                $msg = 'Twitter Search Created';
            }
            $msgclass = 'updated';
            $v = array(
            'aname' => '',
            'keywords' => '',
            'link_category' => '',
            'newlinkcat' => '' 
             ); //Don't let them recreate same one
        }
    }
    
    //Start screen page
    ?>
    <div class='wrap'>
    <?php screen_icon('link-manager'); ?>
    <h2>Create Google News Feed or Twitter Search</h2>
    <?php 
    if (!empty($msg)){ ?>
       <div id=”message” class="<?php echo $msgclass; ?>" ><p><strong><?php echo $msg ; ?></strong></p></div>
    <?php } ?>    
    <p>Use this option to create a google news feed or twitter search that will be placed into your links for the link category you choose.  
        You can then use this
        feed in any of your MyCurator Topics by including the link category as a source.</p>
    <p><strong>All fields are required except a New Link Category</strong></p>
    <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI'] ); ?>"> 
        <table class="form-table" >
            <tr>
                <th scope="row">Google News Feed? </th>
                <td><input name="ftype" type="radio" value="G" checked /></td>
            </tr>
            <tr>
                <th scope="row">Twitter Search Feed? </th>
                <td><input name="ftype" type="radio" value="N" unchecked /></td>    
            </tr>  
            <tr>
                <th scope="row">Feed Name</th>
                <td><input name="aname" type="input" id="aname" size="50" maxlength="200" value="<?php echo $v['aname']; ?>" /></td>    
            </tr>            
            <tr>
                <th scope="row">Feed Keywords</th>
                <td><input name="keywords" type="input" id="keywords" size="50" maxlength="200" value="<?php echo esc_attr($v['keywords']); ?>" /></td>    
            </tr>
            <tr>
                <th scope ="row">Link Category for Feed</th>
                <td><?php wp_dropdown_categories($cats); ?></td>
            </tr> 
            <tr>
                <th scope="row">OR Enter a New Link Category</th>
                <td><input name="newlinkcat" type="input" id="newlinkcat" size="50" maxlength="200" value="<?php echo $v['newlinkcat']; ?>" /></td>    
            </tr>
       </table>
        <?php wp_nonce_field('mct_ai_newsfeed','tweetnews'); ?>
        <div class="submit">
          <input name="Submit" type="submit" value="Create Feed" class="button-primary" />
        </div>
       </form> 
    </div>    

    <?php
}

function bwc_do_news_create($v,$feedtype = true){
    //Create a Google news feed or a twitter search feed from the keywords 
    //$v is an array with the name, keywords and category of the link
    //Returns an error as a string or a numeric link id
    
    //Get the keywords
    $newsterm = urlencode($v['keywords']); 

    //Build the rss string
    if ($feedtype) {
        $newsfeed = 'http://news.google.com/news?hl=en&gl=us&q='.$newsterm.'&um=1&ie=UTF-8&output=rss'; //Google news feed
        $linkurl = 'http://news.google.com/';
    } else {
        $newsfeed = 'http://search.twitter.com/search.rss?q='.$newsterm;  //twitter search string
        $linkurl = 'http://search.twitter.com/';
    }
    $current_user = wp_get_current_user();  //Owner of this link
    //Create the new Link Record
    $linkdata = array(
 	"link_url"		=> $linkurl, // varchar, the URL the link points to
	"link_name"		=> $v['aname'], // varchar, the title of the link
	"link_visible"		=> 'Y', // varchar, Y means visible, anything else means not
	"link_owner"		=> $current_user->ID, // integer, a user ID
	"link_rss"		=> $newsfeed, // varchar, a URL of an associated RSS feed
	"link_category"		=> $v['link_category'] // int, the term ID of the link category. 
    );
    $linkval = wp_insert_link( $linkdata, true );
    if (is_wp_error($linkval)){
        return $linkval->get_error_message();
    }
    return $linkval;
}

function bwc_add_msg($msg, $addmsg){
    //appends messages to the message string
    if (empty($msg)){
        $msg = $addmsg;
    } else {
        $msg .= ' And '.$addmsg;
    }
    return $msg;
}


?>