<?php
/* tgtai_classigy
 * This file contains the code to train the classifier with a given document.  The classifier is an object.  It also contains the code to get words 
 * from a page as a function.
*/

function mct_ai_get_words($page, $dup=true){
    //$page is the page in rendered format
    //this function returns an array of words stripped from the article, or '' if no words found
    //if dup is false, it won't remove duplicates
    
    global $stopwords, $threeletter;

    //Is this a rendered, formated page?
    if (strpos($page, 'savelink-article')=== false){
        return '';  
    }
    $title = '';
    $author = '';
    $article = '';
    //$page has the content, with html, using the format of rendered pGE function, separate sections
    $cnt = preg_match('{<title>([^<]*)</title>}i',$page,$matches);
    if ($cnt) $title = $matches[1];
    $cnt = preg_match('{<div>Author:([^<]*)</div>}',$page, $matches);
    if ($cnt) $author = $matches[1];
    $cnt = preg_match('{<span class="mct-ai-article-content">(.*)}si',$page,$matches);  //don't stop at end of line
    if ($cnt) $article = $matches[1];
    //Get rid of tags, non-alpha
    $title = preg_replace('{<([^>]*)>}',' ',$title);  //remove tags but leave spaces
    $title = preg_replace('{[^A-Za-z\s\s+]}',' ',$title); //remove non-alpha

    $author = preg_replace('{[^A-Za-z\s\s+]}',' ',$author); //remove non-alpha

    $article = preg_replace('@<style[^>]*>[^<]*</style>@i','',$article);  //remove style tags
    $article = preg_replace('{<([^>]*)>}',' ',$article);  //remove tags but leave spaces
    $article = preg_replace('{&[a-z]*;}',"'",$article);  //remove any encoding
    $article = preg_replace('{[^A-Za-z\s\s+]}',' ',$article); //remove non-alpha
    //split sections  into words and merge
    $awords = preg_split('{\s+}',$article);
    if (empty($awords)){
        return '';  //no words in the body to work with
    }
    $auwords = preg_split('{\s+}',$author);
    $twords = preg_split('{\s+}',$title);
    $awords = array_merge($twords, $auwords, $awords );
    //remove stop words
    $words = array();
    foreach ($awords as $a){
        $a = trim($a);
        if (strlen($a) < 4){  //not long enough?
            if (strlen($a) < 2){ 
                continue;
            } else {
                if (!in_array(strtolower($a),$threeletter)){  //Check our 3 letter word list
                    $cnt = preg_match('{^[^a-z0-9]*$}',$a);  //Allow 2 and 3 word acronyms if all caps
                    if (!$cnt){
                        continue;
                    }
                }
            }
        }
        $a = strtolower($a); //now make lowercase
        // //$a = PorterStemmer::Stem($a);
        if (in_array($a,$stopwords)){  //a stopword?
            continue;
        }
        if ($dup){
            if (!in_array($a,$words)){  //no dups
                $words[] = $a;          //got a good word
            }
        } else {
            $words[] = $a;
        }
    }
    
    return $words;
}

function mct_ai_no_dups($words){
    //remove duplicates from an array of words
    
    if (empty($words)) return;
    $nodup = array();
    foreach($words as $w){
        if (!in_array($w,$nodup)){  //no dups
            $nodup[] = $w;          //got a good word
        }
    }
    return $nodup;
}

define ('MCT_AI_GOODPROB',.95);
define ('MCT_AI_BADPROB',.95);
define ('MCT_AI_MAXDICT', 1000);
define ('MCT_AI_MINDICT', 50);

class Relevance {
    //Set up basic variables
    
    //$fc is an array of features, with each feature being an array of the 
    //two categories 'bad' and 'good' - so fc['word']['good'] will be a value (as will 'bad')
    private $fc = array();  
    private $cc = array();  //Count of documents in category, two keys 'good' and 'bad'
    private $laplace = 1;   //laplace smoothing of 0 value feature/category combination
    private $categories = array('good', 'bad');  //Valid categories 
    private $cur_shrink = 0;  //holds the current count of where we shrunk the db
    
    public function get_db($topic){
        global $wpdb, $ai_topic_tbl;
        //This function gets the fc and cc databases out of the topic record
        //This must be called before classifying any documents
        //It may not be called if you are going to train from scratch
        //Topic name is used to find the topic record storing the database

        $sql = "SELECT topic_aidbfc, topic_aidbcat
            FROM $ai_topic_tbl
            WHERE topic_name = '$topic'";
        $dbs = $wpdb->get_row($sql, ARRAY_A);
        if (empty($dbs)){
            return "ERROR No Topic Found";
        }
        $this->fc = maybe_unserialize($dbs['topic_aidbfc']);
        $this->cc = maybe_unserialize($dbs['topic_aidbcat']);
        if (empty($this->fc)){
            return "ERROR No relevance database";
        }
        return '';
    }
    
    public function set_db($topic){
        global $wpdb, $ai_topic_tbl;
        //This function stores the fc and cc databases into the topic of record
        //This should not be called unless you have finished a training session
        //Topic name is used to find the topic record storing the database
        $datavals = array(
            'topic_aidbfc' => maybe_serialize($this->fc),
            'topic_aidbcat' => maybe_serialize($this->cc)
        );
        $where = array('topic_name' => $topic);
        $wpdb->update($ai_topic_tbl, $datavals, $where);
    } 
    
    private function shrinkdb($min){
        //Shrink the database to reduce dimensionality
        //remove any features that have <= $min counts across all categories
        $remove_f = array();
        foreach ($this->fc as $key => $f){
            $cnt = 0;
            foreach ($this->categories as $cc) {
                $cnt += $f[$cc];
            }
            if ($cnt <= $min){
                $remove_f[] = $key;
            }
        }
        if (empty($remove_f)) return;
        foreach ($remove_f as $f) {
            unset($this->fc[$f]);
        }
        
    }
    
    private function catcount($cat){
        //The number of documents in a category
        if (array_key_exists($cat, $this->cc)){
            return $this->cc[$cat];
        }
        return 0;
    }
    
    private function dictsize(){
        //Total number of features in db
        return count($this->fc);
    }
    
    public function train($item, $cat){
        //An item is an array of words, and each word f will be counted in fc[f][cat]
        //then the cc table for this cat will be incremented for 1 document
        if (!in_array($cat, $this->categories)) return;  //not a valid category
        foreach ($item as $f){
            if (empty($this->fc) || !array_key_exists($f,$this->fc)){
                //Set up all categories with a 0 count to start
                foreach ($this->categories as $cc) {
                    $this->fc[$f][$cc] = 0;
                }
            }
            $this->fc[$f][$cat] += 1;  //increment counts for every feature
        }
        //Update the document count for this category
        if (empty($this->cc) || !array_key_exists($cat, $this->cc)){
            $this->cc[$cat] = 0;
        }
        $this->cc[$cat] += 1;
    }
     
    public function preparedb(){
        //Before classifying it shrinks the database to lower the chance of a calc getting
        // too small.  It shrinks the db until we get to under our target size
        $cur_size = 0;  // starting minimum word counts, go up from here
        $origdb = $this->fc;
        while ($this->dictsize() > MCT_AI_MAXDICT){
            $cur_size += 1;
            $this->shrinkdb($cur_size);  
        }
        $this->cur_shrink = $cur_size;
        return $cur_size;
    }
    
    public function report($topic){
        //This returns the value for the statistics report on the topic page for admin's
        $report = array();
        if ($this->get_db($topic) == ''){
            foreach ($this->categories as $cat){
                $report[$cat] = $this->catcount($cat);
            }
            $report['dict'] = $this->dictsize();
            $report['shrinkdb'] = $this->preparedb();
            $diff = 0;
            foreach ($this->fc as $f){
                $diff += abs($f['good'] - $f['bad']);
            }
            $report['coef'] = $diff/$this->dictsize();
       }
       return $report;
    }
}  //end class 

function mct_ai_trainpost($postid, $tname, $cat){
    global $wpdb, $ai_topic_tbl, $ai_sl_pages_tbl;
    // This function trains a topic relevance engine with a saved page from a post
    // postid is the post, tname is the topic name and cat is good/bad category
    
    // Get object and load the db's
    $rel = new Relevance();
    $rel->get_db($tname);
    
    //Get the page id from the post and get the page 
    $sl_ids = mct_ai_getsavedpageid($postid);
    if (count($sl_ids) != 1) return;  //Can only have one link if we train
    $page = mct_ai_getsavedpage($sl_ids[0]);
    $words = mct_ai_get_words($page);
    if (empty($words)) return;
    
    //Train it and save db
    $rel->train($words, $cat);
    $rel->set_db($tname);
    
    //Save trained topic in post meta
    $vals = get_post_meta($postid,'mct_ai_trained',true);
    if (!empty($vals)) {
        $vals[] = $cat.':'.$tname;
    } else {
        $vals = array($cat.':'.$tname);
    }
    update_post_meta($postid, 'mct_ai_trained', $vals);
    
    unset($rel); //Done with the object
}
?>