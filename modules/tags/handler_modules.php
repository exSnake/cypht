<?php

/**
 * @subpackage tags/handler
 */
class Hm_Handler_mod_env extends Hm_Handler_Module {
    public function process() {
        $this->out('mod_support', array_filter(array(
            $this->module_is_supported('imap') ? 'imap' : false,
            $this->module_is_supported('feeds') ? 'feeds' : false,
            $this->module_is_supported('github') ? 'github' : false,
            $this->module_is_supported('wordpress') ? 'wordpress' : false
        )));
    }
}

/**
 * Store a label on messages as an IMAP keyword
 * @subpackage tags/handler
 */
class Hm_Handler_add_tag_to_message extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('tag_id', 'list_path', 'tag'));
        if (!$success || !isset($form['tag']) || $form['tag'] != true) {
            return;
        }
        $ids = explode(',', $form['list_path']);
        $taged_messages = tag_messages_keyword($this, $form['tag_id'], $ids, 'CUSTOM');
        $this->out('taged_messages', $taged_messages);
        if ($taged_messages == count($ids)) {
            Hm_Msgs::add('Tag added');
        } elseif ($taged_messages > 0) {
            Hm_Msgs::add('Some messages have been taged', 'warning');
        } else {
            Hm_Msgs::add('ERRFailed to tag selected messages', 'danger');
        }
    }
}

/**
 * Remove a label keyword from messages
 * @subpackage tags/handler
 */
class Hm_Handler_remove_tag_from_message extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('tag_id', 'list_path', 'untag'));
        if (!$success || !isset($form['untag']) || $form['untag'] != true) {
            return;
        }
        $ids = explode(',', $form['list_path']);
        $untaged_messages = tag_messages_keyword($this, $form['tag_id'], $ids, 'UNCUSTOM');
        $this->out('untaged_messages', $untaged_messages);
        if ($untaged_messages == count($ids)) {
            Hm_Msgs::add('Tag removed');
        } elseif ($untaged_messages > 0) {
            Hm_Msgs::add('Messages have been untaged', 'warning');
        } else {
            Hm_Msgs::add('ERRFailed to remove tag from selected messages', 'danger');
        }
    }
}

/**
 * @subpackage tags/handler
 */
class Hm_Handler_process_tag_update extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('tag_name','parent_tag','tag_id','tag_color'));
        if (!$success) {
            return;
        }
        $tag = array(
            'name' => html_entity_decode($form['tag_name'], ENT_QUOTES),
            'parent' => $form['parent_tag'] ?? null,
            'color' => Hm_Tags::sanitizeColor($form['tag_color'] ?? null)
        );
        if (!empty($form['tag_id']) && Hm_Tags::get($form['tag_id'])) {
            Hm_Tags::edit($form['tag_id'], $tag);
            Hm_Msgs::add('Tag Edited');
        } else {
            $tag['keyword'] = Hm_Tags::keywordFromName($tag['name'], Hm_Tags::getAll());
            Hm_Tags::add($tag);
            Hm_Msgs::add('Tag Created');
        }
        $this->out('tag_success', true);
    }
}

/**
 * @subpackage profile/handler
 */
class Hm_Handler_process_tag_delete extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('tag_delete', 'tag_id'));
        if (!$success) {
            return;
        }
        if (($tag = Hm_Tags::get($form['tag_id']))) {
            Hm_Tags::del($tag['id']);
            Hm_Msgs::add('Tag Deleted');
            $this->out('tag_success', true);
        } else {
            Hm_Msgs::add('Tag ID not found', 'warning');
            return;
        }
    }
}

/**
 * @subpackage tags/handler
 */
class Hm_Handler_imap_tag_content extends Hm_Handler_Module {
    public function process() {
        $data_sources = imap_data_sources();
        $tag_id = $this->request->post['folder'];
        $keyword = $tag_id ? Hm_Tags::keyword($tag_id) : false;
        if (!$data_sources || !$keyword) {
            return;
        }
        try {
            $limit = $this->user_config->get('tag_per_source_setting', DEFAULT_TAGS_PER_SOURCE);
            $since = process_since_argument($this->user_config->get('tag_since_setting', DEFAULT_TAGS_SINCE));
            $msg_list = [];
            foreach ($data_sources as $source) {
                $serverId = $source['id'];
                $folder = $source['folder_name'];
                $mailbox = Hm_IMAP_List::get_connected_mailbox($serverId, $this->cache);
                if (!$mailbox || !$mailbox->authed()) {
                    continue;
                }
                $uids = $mailbox->search($folder, 'ALL', array(array('SINCE', $since), array('KEYWORD', $keyword)));
                if (!$uids) {
                    continue;
                }
                $uids = array_slice($uids, -$limit);
                $server_details = Hm_IMAP_List::dump($serverId);
                foreach ($mailbox->get_message_list($folder, $uids) as $msg) {
                    $msg['server_id'] = $serverId;
                    $msg['folder'] = bin2hex($folder);
                    $msg['server_name'] = $server_details['name'];
                    $msg_list[] = $msg;
                }
            }
            usort($msg_list, function($a, $b) {
                return strtotime($b['internal_date']) - strtotime($a['internal_date']);
            });
            $this->out('imap_tag_data', $msg_list);
        } catch (\Throwable $th) {
            Hm_Msgs::add('Failed to load tag messages: '.$th->getMessage(), 'danger');
        }
    }
}

/**
 * Process "tag_per_source" setting for the tag page in the settings page
 * @subpackage core/handler
 */
class Hm_Handler_process_tag_source_max_setting extends Hm_Handler_Module {
    /**
     * Allowed values are greater than zero and less than MAX_PER_SOURCE
     */
    public function process() {
        process_site_setting('tag_per_source', $this, 'max_source_setting_callback', DEFAULT_TAGS_PER_SOURCE);
    }
}

class Hm_Handler_tag_data extends Hm_Handler_Module {
    public function process() {
        Hm_Tags::init($this);
        $this->out('tags', Hm_Tags::getAll());
    }
}

/**
 * Apply or remove a label keyword on a list of server_uid_folderhex ids
 * @param Hm_Handler_Module $mod handler with cache access
 * @param string $tag_id label id
 * @param array $ids message ids
 * @param string $action CUSTOM adds the keyword, UNCUSTOM removes it
 * @return int messages the server accepted
 */
if (!hm_exists('tag_messages_keyword')) {
function tag_messages_keyword($mod, $tag_id, $ids, $action) {
    $keyword = Hm_Tags::keyword($tag_id);
    if (!$keyword) {
        return 0;
    }
    $done = 0;
    foreach ($ids as $msg_part) {
        $parts = explode('_', $msg_part);
        if (count($parts) != 3) {
            continue;
        }
        list($imap_server_id, $msg_id, $folder) = $parts;
        $mailbox = Hm_IMAP_List::get_connected_mailbox($imap_server_id, $mod->cache);
        if (!$mailbox || !$mailbox->authed()) {
            continue;
        }
        $res = $mailbox->message_action(hex2bin($folder), $action, array($msg_id), false, $keyword);
        if (is_array($res) ? !empty($res['status']) : $res) {
            $done++;
        }
    }
    return $done;
}}
