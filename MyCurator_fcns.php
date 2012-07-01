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
    }
    return $url;
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
        $file_data = curl_get_file_contents($imgurl);
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
function curl_get_file_contents($URL) {
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
