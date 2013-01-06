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

function mct_ai_postthumb($imgurl, $post_id) {
    // Load an image pointed to by a url into the post thumbnail featured image
    // Adapted from code by Aditya Mooley from auto post thumbnail plugin
    

    // Get the file name
    $filename = substr($imgurl, (strrpos($imgurl, '/'))+1);

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
        'post_title' => '',
        'post_content' => '',
    );

    $thumb_id = wp_insert_attachment($attachment, false, $post_id);
    if ( !is_wp_error($thumb_id) ) {
        require_once(ABSPATH . '/wp-admin/includes/image.php');
        
        wp_update_attachment_metadata( $thumb_id, wp_generate_attachment_metadata( $thumb_id, $new_file ) );
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

?>
