<?php
/**
 * Press This Display and Handler.
 *
 * @package WordPress
 * @subpackage Press_This
 */
/*MOD - each mod begins with this in comments
 * Use get-it as admin referer
 * Change title to Get It
 * blockquote selection, use fixed title on url
 */
define('IFRAME_REQUEST' , true);

/** WordPress Administration Bootstrap */
require_once('../../../wp-admin/admin.php');

header('Content-Type: ' . get_option('html_type') . '; charset=' . get_option('blog_charset'));

if ( ! current_user_can('edit_posts') )
	wp_die( __( 'Cheatin&#8217; uh?' ) );

/**
 * Press It form handler.
 *
 * @package WordPress
 * @subpackage Press_This
 * @since 2.6.0
 *
 * @return int Post ID
 */
function press_it() {
    //Use the cloud process to save a training post 
    global $wpdb, $ai_topic_tbl, $mct_ai_optarray, $user_id;
    include_once('MyCurator_local_proc.php');
    
    $tname = sanitize_text_field($_POST['post_category']);
    $url = $_POST['save-url'];
    
    //Get the Topic
    $sql = "Select * From $ai_topic_tbl Where topic_name = '$tname'";
    $topic = $wpdb->get_row($sql, ARRAY_A);
    $postit = false;
    if (!empty($topic)){
        //Post using cloud process
        if (mct_ai_cloudtopic($topic)) {
            $post_arr = array(); 
            $post_arr['current_link'] = $url;
            $post_arr['getit'] = '1';
            $page = 'Not Here';
            $postit = mct_ai_cloudclassify($page, $topic, $post_arr);
            //update the style sheet with the local copy
            $page = $post_arr['page'];
            $page = str_replace("mct_ai_local_style",plugins_url('MyCurator_page.css',__FILE__), $page);
            $post_arr['classed'] = 'not sure';
            if ($postit) mct_ai_post_entry($topic, $post_arr, $page);
        }
    }
    if ($postit) return 1;
    
    //Didn't render page or post correctly, just create an excerpt with the selection and post to training page
    $selection = isset($_POST['selection']) ? sanitize_text_field($_POST['selection']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $hoststr = parse_url($url,PHP_URL_HOST);
    $content = '';
    if ( !empty($selection)) $content .= '<blockquote>' . $selection . '</blockquote>';
    $content .= '<p><a href="' . $url . '">'.$mct_ai_optarray['ai_orig_text']." ".$hoststr.'</a></p>';
    $details = array(
      'post_content'  => $content,
      'post_author' => $user_id,
      'post_title'  =>  $title,
      'post_name' => sanitize_title($title),
      'post_status' => 'publish'
    );
    //Save the excerpt field?
    //ai_nosave_excerpt
    if ($mct_ai_optarray['ai_nosave_excerpt']) {
        //don't save
    } else {
        $details['post_excerpt'] = $content;
    }
    //Taxonomy
    $details['tax_input'] = array (  //add topic name 
        'topic' => $topic['topic_name'],
        'ai_class' => 'not sure' //add ai class
    );
    $details['post_type'] = 'target_ai'; //post as a target
    //and post it
    $post_id = wp_insert_post($details);
    
    return $post_id;
}

// For submitted posts.
if ( isset($_REQUEST['action']) && 'post' == $_REQUEST['action'] ) {
	check_admin_referer('MyCurator_getit');//MOD - use correct admin referer
	$posted = $post_ID = press_it();
} else {
	$post = get_default_post_to_edit('target_ai', true);  //Set to custom post type for MyCurator
	$post_ID = $post->ID;
}

// Set Variables
$title = isset( $_GET['t'] ) ? trim( strip_tags( html_entity_decode( stripslashes( $_GET['t'] ) , ENT_QUOTES) ) ) : '';

$selection = '';
if ( !empty($_GET['s']) ) {
	$selection = str_replace('&apos;', "'", stripslashes($_GET['s']));
	$selection = trim( htmlspecialchars( html_entity_decode($selection, ENT_QUOTES) ) );
}

if ( ! empty($selection) ) {
	$selection = preg_replace('/(\r?\n|\r)/', '</p><p>', $selection);
	$selection = '<p>' . str_replace('<p></p>', '', $selection) . '</p>';
}

$url = isset($_GET['u']) ? esc_url($_GET['u']) : '';
$image = isset($_GET['i']) ? $_GET['i'] : '';

wp_enqueue_style( 'colors' );
wp_enqueue_script( 'post' );
_wp_admin_html_begin();
?>
<title><?php _e('Get It') ?></title> <!-- MOD change title to get it -->
<script type="text/javascript">
//<![CDATA[
addLoadEvent = function(func){if(typeof jQuery!="undefined")jQuery(document).ready(func);else if(typeof wpOnload!='function'){wpOnload=func;}else{var oldonload=wpOnload;wpOnload=function(){oldonload();func();}}};
var userSettings = {'url':'<?php echo SITECOOKIEPATH; ?>','uid':'<?php if ( ! isset($current_user) ) $current_user = wp_get_current_user(); echo $current_user->ID; ?>','time':'<?php echo time() ?>'};
var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>', pagenow = 'press-this', isRtl = <?php echo (int) is_rtl(); ?>;
var photostorage = false;
//]]>
</script>

<?php
	do_action('admin_print_styles');
	do_action('admin_print_scripts');
	do_action('admin_head');
?>
	<style type="text/css">
	#message {
		margin: 10px 0;
	}
	#title,
	.press-this #wphead {
		margin-left: 0;
		margin-right: 0;
	}
	.rtl.press-this #header-logo,
	.rtl.press-this #wphead h1 {
		float: right;
	}
        .posting {
            margin-right: 50px;
        }
        body.press-this {
            min-width: 275px;
            min-height: 200px;
        }
        #titlediv {
            font-size: 1.3em;
        }
</style>
<script type="text/javascript">
jQuery(document).ready(function($) {
    jQuery('#publish, #submit').click(function() { jQuery('#saving').css('display', 'inline'); });
});
</script>
</head>
<body class="press-this wp-admin<?php if ( is_rtl() ) echo ' rtl'; ?>">
<form action="MyCurator_getit.php?action=post" method="post">   <!-- MOD post to MyCurator_getit.php -->
<div id="poststuff" class="metabox-holder">
        <?php wp_nonce_field('MyCurator_getit') ?>  <!-- MOD nonce get-it -->
        <!-- Mod: Create hidden fields for selection and url so they can be added to content if page doesn't render -->
        <input type="hidden" id="selection" name="selection" value="<?php echo strip_tags($selection,'<p><a>'); ?>" />
        <input type="hidden" id="save-url" name="save-url" value="<?php echo  $url ; ?>" />
        <input type="hidden" id="title" name="title" value="<?php echo  $title ; ?>" />
        
	<div class="posting">

		<div id="wphead">
			<!-- <img id="header-logo" src="<?php echo esc_url( includes_url( 'images/blank.gif' ) ); ?>" alt="" width="16" height="16" /> -->
			<h1 id="site-heading">
				<a href="<?php echo get_option('home'); ?>/" target="_blank">
					<span id="site-title"><?php bloginfo('name'); ?></span>
				</a>
			</h1>
		</div>

		<?php
		if ( isset($posted) && intval($posted) ) {
			$post_ID = intval($posted); ?>
			<div id="message" class="updated">
			<p><strong><?php _e('Your post has been saved.'); ?></strong>
			<a href="#" onclick="window.close();"><?php _e('Close Window'); ?></a></p>
                        <script type="text/javascript">setTimeout('self.close();',2000);</script>
			</div>
		<?php exit(); } ?>

		<div id="titlediv">
			<div class="titlewrap">
				<?php echo esc_attr($title);?>
			</div>
		</div>

        <br />               
        <?php //Get Values from Db
        $sql = "SELECT `topic_name` FROM $ai_topic_tbl WHERE topic_status != 'Inactive'";
        $topics = $wpdb->get_col($sql); 
        if (empty($topics)) {
            echo "<h2>You must first create a Topic in MyCurator to use the Get It Bookmarklet</h2>";
            echo '<input name="close" id="close" value="Close" type="submit" class="button-primary" onclick="window.close(); return false;">';
        } else { ?>
        
            <div id="categorydiv" class="postbox">
                    <h3 class="hndle"><?php _e('Topics') ?></h3>
                    <div class="inside">
                    <div id="taxonomy-category" class="categorydiv">
                        <?php $check = "checked"; foreach ($topics as $topic) { ?>
                        <p><input name="post_category" type="radio" value="<?php echo $topic; ?>" <?php echo $check; ?>  /> <?php echo $topic; ?></p>
                        <?php $check = ""; } ?>
                    </div>
                    </div>
             </div>
            <input name="submit" id="submit" value="Save to Training Page" type="submit" class="button-primary">
            <img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" id="saving" style="display:none;" />
        <?php } //else on topics ?>
        </div>
</div>
</form>
<?php
do_action('admin_footer');
do_action('admin_print_footer_scripts');
?>
<script type="text/javascript">if(typeof wpOnload=='function')wpOnload();</script>
</body>
</html>
