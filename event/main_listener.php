<?php
/**
 *
 * FPB to cmBB Extension. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2018, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */
namespace ger\fpb2cmbb\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Main event listener
 */
class main_listener implements EventSubscriberInterface
{
    private $cmbb;
    private $fpb;
    private $language;
    private $helper;
    
    static public function getSubscribedEvents()
    {
        return array(
            'ger.feedpostbot.submit_post_before'            => 'post_article',
            'ger.feedpostbot.acp_override_feed_block_vars'	=> 'fpb_acp_set_vars',
        );
    }
	
    
    public function __construct(\ger\cmbb\cmbb\driver $cmbb, \ger\feedpostbot\classes\driver $fpb, \phpbb\language\language $language, \phpbb\controller\helper $helper) 
    {
        $this->cmbb = $cmbb;
        $this->fpb = $fpb;
        $this->language = $language;
        $this->helper = $helper;
    }

    /**
     * Append info in the ACP
     * @param \phpbb\event\data $event The event object
     */
    public function fpb_acp_set_vars($event)
    {
        
        $categories = $this->cmbb->get_categories(true);
        if ($categories)
        {
            // Gather current info and strip currently selected forum
            $source = $event['source'];
            $selected = $source['forum_id'];
            $block_vars = $event['block_vars'];
            $forumlist = str_replace(' selected="selected"', '', $block_vars['S_FORUMS']);
            
            foreach ($categories as $id => $name)
            {
                $forumlist.= '<option value="cmbb_' . $id . '">cmBB: ' . $name . '</option>';
            }
            
            // Now apply the selected forum and pass back to the event
            $block_vars['S_FORUMS'] = str_replace('value="' . $source['forum_id'] . '">', 'value="' . $source['forum_id'] . '"  selected="selected">', $forumlist);
            $event['block_vars'] = $block_vars;
        }
    }

    /**
	 * Override the actual posting intead post as article
	 * @param \phpbb\event\data $event The event object
	 */	
	public function post_article($event)
	{
		// We need to duplicate the vars first
		$title = $event['title'];
		$rss_item = $event['rss_item'];
		$source = $event['source'];
        
        if (substr($source['forum_id'], 0, 4) == 'cmbb') 
        {
            $event['do_post'] = false; // Prevent regular posting
            $category_id = (int) str_replace('cmbb_', '', $source['forum_id']);
            
            // Only show excerpt of feed if a text limit is given, but make it nice
            if (!empty($source['textlimit']))
            {
                $content = $this->fpb->closetags($this->fpb->character_limiter($rss_item['description'], $source['textlimit']));
                if (!empty($source['append_link']))
                {
                    $content .= '<p><a href="' . $rss_item['link'] . '">' . $this->language->lang('FPB_READ_MORE') . '</a></p>';
                }
            }
            else
            {
                $content = $rss_item['description'];
                if (!empty($source['append_link']))
                {
                    $content .= '<p>' . $this->language->lang('FPB_SOURCE') . ' <a href="' . $rss_item['link'] . '">' .  $this->fpb->character_limiter($rss_item['link'], 50) . '</a></p>';
                }
            }
            
            // Some feeds are doing really weird stuff with images that might cmBB or even phpBB choke. 
            $content = preg_replace('/\<img(.*?)src=["\']?([^"\'>]+)["\']?(.*?)\>/is', '<br><img src="$2" /><br>', $content);
            
            $article_data = array(
                'title'			 => $title,
				'alias'			 => $this->cmbb->generate_article_alias($title),
				'user_id'		 => $source['user_id'],
				'parent'		 => $this->cmbb->get_std_parent($category_id),
				'is_cat'		 => 0,
				'category_id'	 => $category_id,
				'content'		 => trim($content),
				'visible'		 => 1,
				'datetime'		 => empty($source['curdate']) ? strtotime($rss_item['pubDate']) : 0,
            );
			$article_data['topic_id'] = $this->create_article_topic($article_data, $this->cmbb->fetch_category($category_id, true)['react_forum_id'], $rss_item);
            $article_id = $this->cmbb->store_article($article_data);
        }
              
	}

    /**
	 * Create a topic with intro for article
	 * @param array $article_data
	 * @param int forum_id
     * @param array $rss_item
	 * @return string
	 */
	private function create_article_topic($article_data, $forum_id, $rss_item)
	{
		if (empty($forum_id))
		{
			return false;
		}
		if (!function_exists('get_username_string'))
		{
			include($this->phpbb_root_path . 'includes/functions_content.' . $this->php_ext);
		}
		if (!function_exists('submit_post'))
		{
			include($this->phpbb_root_path . 'includes/functions_posting.' . $this->php_ext);
		}
		$article_data['user_id'] = (int) $article_data['user_id'];
		if (empty($article_data['user_id']))
		{
			return false;
		}
        $user = $this->cmbb->phpbb_get_user($article_data['user_id'], false);
		if ($user === false)
		{
			return false;
		}

        $intro = $this->fpb->html2bbcode($this->fpb->closetags($this->fpb->character_limiter($article_data['content'], 500)));
        
		$topic_content = '[b][size=150]' . $article_data['title'] . '[/size][/b]
[i]' . $this->language->lang('POST_BY_AUTHOR') . ' ' . $user. '[/i]

' . $intro . '
[url=' . $this->helper->route('ger_cmbb_article', array('alias' => $article_data['alias'])) . ']' . $this->language->lang('FPB_READ_MORE') . '[/url]';

		$poll = $uid = $bitfield = $options = '';

		// will be modified by generate_text_for_storage
		$allow_bbcode = $allow_urls = $allow_smilies = true;

		generate_text_for_storage($topic_content, $uid, $bitfield, $options, $allow_bbcode, $allow_urls, $allow_smilies);

		$data = array(
			// General Posting Settings
			'forum_id'			 => $forum_id, // The forum ID in which the post will be placed. (int)
			'topic_id'			 => 0, // Post a new topic or in an existing one? Set to 0 to create a new one, if not, specify your topic ID here instead.
			'icon_id'			 => false, // The Icon ID in which the post will be displayed with on the viewforum, set to false for icon_id. (int)
			// Defining Post Options
			'enable_bbcode'		 => true, // Enable BBcode in this post. (bool)
			'enable_smilies'	 => true, // Enabe smilies in this post. (bool)
			'enable_urls'		 => true, // Enable self-parsing URL links in this post. (bool)
			'enable_sig'		 => true, // Enable the signature of the poster to be displayed in the post. (bool)
			// Message Body
			'message'			 => $topic_content, // Your text you wish to have submitted. It should pass through generate_text_for_storage() before this. (string)
			'message_md5'		 => md5($topic_content), // The md5 hash of your message
			// Values from generate_text_for_storage()
			'bbcode_bitfield'	 => $bitfield, // Value created from the generate_text_for_storage() function.
			'bbcode_uid'		 => $uid, // Value created from the generate_text_for_storage() function.    // Other Options
			'post_edit_locked'	 => 0, // Disallow post editing? 1 = Yes, 0 = No
			'topic_title'		 => $article_data['title'],
			'notify_set'		 => true, // (bool)
			'notify'			 => true, // (bool)
			'post_time'			 => $article_data['datetime'], // Set a specific time, use 0 to let submit_post() take care of getting the proper time (int)
			'forum_name'		 => $this->fpb->get_forum_name($forum_id), // For identifying the name of the forum in a notification email. (string)    // Indexing
			'enable_indexing'	 => true, // Allow indexing the post? (bool)    // 3.0.6
		);
//        var_dump($data, $article_data);die;
		$url = submit_post('post', $article_data['title'], $user, POST_NORMAL, $poll, $data);
		if (strpos($url, 'sid=') !== false)
		{
			$url = substr($url, 0, strpos($url, 'sid='));
		}
		$topic_id = str_replace('&amp;t=', '', strstr($url, '&amp;t='));
		return (int) $topic_id;
	}

}