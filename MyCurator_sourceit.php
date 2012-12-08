<?php
/**
 * Modified Press This to grab RSS Feed sources from web pages
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

$args = array();
$msg = '';
$posted = false;
$url = '';
// For submitted posts.
if ( isset($_REQUEST['action']) && 'post' == $_REQUEST['action'] ) {
	check_admin_referer('MyCurator_sourceit');//MOD - use correct admin referer
        $posted = true;
        $args['link_category'] = strval(absint($_POST['link_category']));
        $args['newlinkcat'] = trim(sanitize_text_field($_POST['newlinkcat']));
        $args['feed_name'] = trim(sanitize_text_field($_POST['feed_name']));
        $args['rss-url'] =  esc_url($_POST['rss-url']);
        $args['save-url'] = esc_url($_POST['save-url']);
        //Validate args
        if (strlen($args['feed_name']) == 0) $msg .= 'Must have a Feed Name. ';
        //if ok, post it
        if ($msg == '') $msg = mct_ai_postlink($args);
} else {
    // Set Variables
    $args['feed_name'] = isset( $_GET['t'] ) ? trim( strip_tags( html_entity_decode( stripslashes( $_GET['t'] ) , ENT_QUOTES) ) ) : '';
    $args['rss-url'] = isset($_GET['u']) ? esc_url($_GET['u']) : '';
    $args['newlinkcat'] = '';
    $url = $args['rss-url'];
    $args['save-url'] = parse_url($url, PHP_URL_HOST);
}

//Get Link Categories for dropdown
$cats = array (
    'orderby' => 'name',
    'hide_empty' => FALSE,
    'name' => 'link_category',
    'taxonomy' => 'link_category'
);
if (isset($args['link_category'])) $cats['selected'] = $args['link_category'];

wp_enqueue_style( 'colors' );
wp_enqueue_script( 'post' );
_wp_admin_html_begin();
?>
<title><?php _e('Source It') ?></title> 
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
<script type="text/javascript" src="http://www.google.com/jsapi"></script>
<script type="text/javascript">
//<![CDATA[
   google.load("feeds", "1");
   function DoFind() {
        <?php echo "var siteurl = '".$url . "';"; ?>
        //Check if anything to do
        if (siteurl == "") return "";
        //Check if google alert page
        if (siteurl.indexOf("google.com/alerts") != -1) return "";
        // Query 
        var query = 'site:' + siteurl;
        google.feeds.findFeeds(query, findDone);
    }

    function findDone(result) {
        //Get first result
        if (!result.error && result.entries.length != 0) {
            var entry = result.entries[0];
            //plug into input values
            jQuery('#rss-url').val(entry.url);
            jQuery('#feed-name').val(entry.title);
            //and display span
            jQuery('#feed-url').text(entry.url);
            jQuery('#feed-title').text(entry.title);
        } else {
            //Hide everything and display the error div
             jQuery('#jquery-message').css('display', 'inline');
             if (!result.error) {
                 jQuery('#feed-error').text("Could not find a Feed");
             } else {
                 jQuery('#feed-error').text(result.error.message);
             }
             jQuery('#categorydiv').css('display','none');
             jQuery('#titlediv').css('display','none');
             jQuery('#submit-button').css('display','none');
        }

    }
    function OnLoadStub() {
        return "";
    }
    google.setOnLoadCallback(OnLoadStub);    
//]]>
</script>
<script type="text/javascript">
//<![CDATA[
jQuery(document).ready(function($) {
   jQuery('#submit').click(function() { jQuery('#saving').css('display', 'inline'); });
    
    DoFind();
});
//]]>
</script>

</head>
<body class="press-this wp-admin<?php if ( is_rtl() ) echo ' rtl'; ?>">
<form action="MyCurator_sourceit.php?action=post" method="post">   
<div id="poststuff" class="metabox-holder">
        <?php wp_nonce_field('MyCurator_sourceit') ?>  
        <!--  Create hidden fields for feed url and link url so they can be added to feed entry, these are set by jQuery if not a google alert page -->
        <input type="hidden" id="save-url" name="save-url" value="<?php echo  $args['save-url'] ; ?>" />
        <input type="hidden" id="rss-url" name="rss-url" value="<?php echo  $args['rss-url'] ; ?>" />
        
	<div class="posting">

		<div id="wphead">
			<h1>
				<a href="<?php echo get_option('home'); ?>/" target="_blank"><?php bloginfo('name'); ?></a>
			</h1>
                    
		</div>

		<?php
		if ( empty($msg) && $posted) {?>
			<div id="message" class="updated">
			<p><strong><?php _e('Your Feed URL has been saved.'); ?></strong>
			<a href="#" onclick="window.close();"><?php _e('Close Window'); ?></a></p>
			</div>
		<?php exit(); } ?>
                <div id="jquery-message" style="display:none; color: red; font-size: 18px;">
			<p><strong><span id="feed-error"><?php _e('Error Finding Feed'); ?></span></strong>
			<a href="#" onclick="window.close();"><?php _e('Close Window'); ?></a></p>
			</div>
		<div id="titlediv">
			<div class="titlewrap">
				<p>Feed Title: <span id="feed-title" style="font-size:12px;"><?php echo $args['feed_name']; ?></span></p>
                                <p>Feed URL: <span id="feed-url" style="font-size:12px;"><?php echo $args['rss-url']; ?></span></p>
			</div>
		</div>

            <br /> 
            <?php if ($msg != '' && $posted) { ?>
                    <div id="message" class="error" ><p><strong><?php echo "FEED NOT CREATED: ".$msg ; ?></strong></p></div>
            <?php } ?>
            <div id="categorydiv" class="postbox">
                <h3 class="hndle"><?php _e('Add Source Link') ?></h3>
                <div class="inside">
                <div id="taxonomy-category" class="categorydiv">
                    <p>Feed Name: <input name="feed_name" id="feed-name" type="input" size="50" maxlength="200" value="<?php echo  $args['feed_name'] ; ?>" /> </p>
                    <p>Choose Link Category: <?php wp_dropdown_categories($cats); ?></p>
                    <p>or Add New Link Category: <input name="newlinkcat" type="input" size="50" maxlength="200" value="<?php echo  $args['newlinkcat'] ; ?>" /> </p>
                    <p><em>Create a new category rather than use BlogRoll</em></p>
                </div>
                </div>
             </div>
            <div id="submit-button">
            <input name="submit" id="submit" value="Save New Source" type="submit" class="button-primary">
            <img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" id="saving" style="display:none;" />
            </div>
        </div>
</div>
</form>
<?php
do_action('admin_footer');
do_action('admin_print_footer_scripts');
?>



</body>
</html>
