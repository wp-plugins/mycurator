<?php
//MyCurator_link_redir.php
//
// These functions handle the redirection to the saved pages as well
//  as deleting and displaying saved page links
//
//Metabox to show link data on post page
add_action('add_meta_boxes','mct_sl_linkmeta');
//Remove saved page from DB if deleting post
add_action('before_delete_post','mct_sl_deletefile');
//add rewrite rule on init
add_action('init','mct_sl_add_rule');
//add filter on template redirect for rewrite
add_action('template_redirect','mct_sl_temp_redir');
add_filter('query_vars','mct_sl_qvar');


function mct_sl_add_rule(){
    //add ailink rule for saved db pages
    add_rewrite_rule('^(.*)/?'.MCT_AI_REDIR.'/([^/]+)/?$','index.php?aipageid=$matches[2]','top');
}
function mct_sl_qvar($vars){
    //query vars for rewrite
    $vars[]= 'aipageid';  //save pages in db link
    return $vars;
}
function mct_sl_temp_redir(){
    //Set up redirection
    global $userdata, $ai_sl_pages_tbl, $currenturl;

    //Redirect based on save page redirection
    $page_id = intval(get_query_var('aipageid'));

    if ($page_id != ''){
        $vals = mct_sl_getsavedpage($page_id);
        $page = $vals['sl_page_content'];

        //If page didn't render, we will find a redirect comment, with a url to redirect too
        $pos = preg_match('@Redirect{([^}]*)}@',$page,$matches);
        if ($pos){
            $sendback = wp_get_referer();  //How to get back
            header('Content-Type: text/html');
            header('Referer: '.$sendback);
            wp_redirect($matches[1]);
            exit;
            
        } else {  //display the saved page
            header('Content-Type: text/html');
            echo($page);
            exit();
        }
    }
}

function mct_sl_getsavedpage($sl_id){
    //this function returns the page content and post id for a saved page given a page id
    global $ai_sl_pages_tbl, $wpdb;
    
    $sql = "SELECT `sl_page_content`, `sl_post_id`
            FROM $ai_sl_pages_tbl
            WHERE sl_page_id = '$sl_id'";
    $vals = $wpdb->get_row($sql, ARRAY_A);
    return $vals;
}

function mct_sl_deletefile($post_id){
    //Hook to remove saved pages from db when post is deleted
    global $wpdb, $ai_sl_pages_tbl;
    // Get the links from the meta data, allow for more than one
    $newlinks = get_post_meta($post_id,'mct_sl_newurl',true);
    if (!empty($newlinks)){
        foreach ($newlinks as $nlink){
            $pos = preg_match('{/'.MCT_AI_REDIR.'/(.*)$}',$nlink,$matches); 
            if ($pos){
                $sl_id = intval(trim($matches[1]));
                $sql = "DELETE FROM $ai_sl_pages_tbl WHERE sl_page_id = $sl_id";
                $del = $wpdb->query($sql);
            }
        }
    }
}

function mct_sl_linkmeta(){
    // Set up meta box for link replacement data
    add_meta_box('mct_sl_metabox','Link Replacement for MyCurator','mct_sl_linkmetashow','post','normal','high');
}

function mct_sl_linkmetashow($post){
    //Show the original and new links for the post
    $origlinks = get_post_meta($post->ID,'mct_sl_origurl',true);
    $newlinks = get_post_meta($post->ID,'mct_sl_newurl',true);
    if ($origlinks == ''){
        return;
    }
    ?><table> <?php
    for ($i=0;$i<count($origlinks);$i++){
        ?>
    <tr>
        <td><em>Original Link: </em></td>
        <td><?php echo $origlinks[$i]; ?></td>
    </tr>
    <tr>
        <td><em>New Link: </em></td>
        <td><?php echo $newlinks[$i]; ?>
    </tr>
    <?php }  //end for loop ?>
  
   
    </table> <?php
}


?>