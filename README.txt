=== MyCurator ===
Contributors: mtilly
Donate link: 
Tags: content curation, curation tools, curation software, content marketing, content management, content discovery, marketing, rss feed
Requires at least: 3.1
Tested up to: 3.4.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

MyCurator is a complete content curation tool with a unique AI feed reader that learns to find just the 
content you want. 

== Description ==

MyCurator is a complete content curation software tool for WordPress blogs.  
MyCurator works behind the scenes every day to find new articles, and new inspiration, for your curation efforts.  
Rather than make you search for new content, it pushes the content it finds to the private training page on your site. 
Each article found by MyCurator includes the full text and all images as well as the attribution to the original page, 
right in the WordPress editor.  You can easily grab quotes and images for your curated post, adding your insights 
and comments, quickly creating newly curated content for your blog.

Like a personal assistant, MyCurator uses AI software learning techniques to weed out 90% or more 
of the articles in your feeds, alerts and blogs, focusing on topics you've trained it to follow. 
This can save you hours every day. You can choose to automatically have the good articles posted 
to a specific category on your blog. Or you may choose to manually curate the best insights from 
the articles into your own blog posts.

MyCurator started as a hosted offering for businesses who provided targeted articles and insights to their 
customers to drive engagement and customer retention.  As a Wordpress plugin, MyCurator connects to the same 
cloud process that powers the hosted business sites.  This allows MyCurator to maintain a low processing 
overhead on your blog.

* The full text and all images for each article are available as a meta box in the WordPress post editor.
* Attribution to the original article is automatically inserted into the curated post.
* After training, you will typically see only 5 to 10 good articles out of 100 read by MyCurator.
* Training is simple with a click of a thumbs up or thumbs down on each article.
* MyCurator finds articles in any language, and all posts are in the native language of your blog.
* Works with any RSS source of articles including Google alerts, blogs, news feeds even twitter searches.
* Targeted news feeds and twitter searches can be created with MyCurator from within WordPress.
* You can choose to automatically have the good articles posted to a specific category on your blog.
* Or you may choose to manually curate the best insights from the articles into your own blog posts.
* Each article is saved as a readable page, like Instapaper, using Diffbot technology.

MyCurator is free for individuals and non-profits, but you will have to obtain an API Key to access the 
cloud services.  For businesses, those promoting products, internet marketers and other heavy users, low priced monthly plans are 
available - always with a free 30 day trial.  Visit http://www.target-info.com/ for more information.

== Installation ==

Using the WordPress Plugin Installer, Choose Add New and then Upload, choose the zip file on your computer that 
you downloaded from the WordPress repository.  After Uploading, choose Install and then activate

After activation choose the new MyCurator menu item and follow the Getting Started instructions.  

In the Important Links section, click on the Get API Key link to get your API Key.  Paste your API Key into 
the API Key field on the MyCurator Options menu item.


== Frequently Asked Questions ==

= Does MyCurator work with most themes? =

MyCurator creates a simple excerpted blog post, with a link to the original page.  It should work with most
themes. Optionally, it will try to save an image from the article as the featured image for the post.

= Does MyCurator work with Network Sites? =

Yes, MyCurator works with Network sites.  You must have a key for each site.  Enterprise options are available 
for businesses and larger sites that need many keys.

= Does MyCurator work with other languages? =

Yes, MyCurator will read and post articles written in other languages.  All characters in the UTF-8 encoding, the same
used by WordPress, will be displayed.  You can customize the link to the original page to match your language.  
The admin pages, documentation and the training videos are only in English at this time.

= How often does MyCurator read my sources for articles? =

You can set MyCurator to process every 3, 6, 12 or 24 hours, depending on how often new articles are posted to 
your sources.  Processing happens in the background, with most of the processing off-site using our
cloud services.

== Screenshots ==

1. Classification Report of how MyCurator has processed your articles.

2. Topic page tells MyCurator what to look for in your sources.

3. New saved page meta box with images in WordPress post editor.

== Changelog ==

= 1.2.0 =
* New Get It Bookmarklet lets you save content as you browse the web on your computer, tablet or phone directly to your training page.  
* Get It Bookmarklet <a href="http://www.target-info.com/training-videos/" />Training Video</a> and <a href="http://www.target-info.com/documentation-2/documentation-get-it/" >Documentation</a>
* Ready for WordPress 3.5 - You must upgrade to version 1.2.0 or later for MyCurator to work with WordPress 3.5 as they are making changes to the Links pages.
* Support for new pricing by Topic, see http://www.target-info.com/pricing/ and the MyCurator Topic pricing widget near the bottom of that page.

= 1.1.4 =
* Fixed problems with non-English characters in the Topic Keywords not matching articles.
* Added option to filter and train non-English articles using UTF-8 character processing to handle extended character sets
* Added links to documentation on the Administrative pages
* Fixed a problem with Filter type topics not having Trash and [Make Live] tags

= 1.1.3 =
* Added new option to choose the Admin or Editor user that will be used in MyCurator posts
* Added MyCurator article processing statistics to MyCurator initial menu page
* Added link to MyCurator documentation on MyCurator initial menu page

= 1.1.2 =
* Fix to delete saved featured post thumbnail image when a training post is deleted
* If you saved first picture as a featured post thumbnail, you will find many Unattached images in your Media Library
* Remove unattached images by choosing Unattached link at top of Media Library page, then use Bulk Actions to Delete Permanently
* See http://www.target-info.com/2012/09/01/update-to-version-1-1-2-to-fix-image-delete-problem/ for more information

= 1.1.1 =
* To support languages, you can customize the text of the link to the original or saved page
* Fix to allow special language characters in article excerpt
* Allow deletion of any Topic
* New option to Not store excerpt field on post
* More validation on Topic Name
* Trim spaces from API Key entry

= 1.1.0 =
* Display article full text in WordPress post editor meta box
* Display all images found in-line in text as well as an images section at bottom of meta box
* Option to insert link to original page into article posts created by MyCurator
* Option to set excerpt length for article posts created by MyCurator
* Option to directly enter the WordPress post editor when making a training post Live

= 1.0.7 =
* New option to support manual content curation, which keeps good trained posts on the training page
* Added link to MyCurator support forum on the wordpress.org site
* Fix to ensure deletion of saved pages when corresponding post is deleted

= 1.0.6 =
* A few more Changes to support WordPress plugin repository

= 1.0.5 =
* Changes to support WordPress plugin repository
* Support for multisite installations
* Access to getting an API Key from getting started and options pages

= 1.0.4 =
* Updates to support WordPress 3.4
* Changes to support themes using query arg hooks

