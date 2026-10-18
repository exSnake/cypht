<?php

use PHPUnit\Framework\TestCase;

/**
 * tests for modules/tags/handler_modules.php
 */
class Hm_Test_Tags_Handler_Modules extends TestCase {

    public function setUp(): void {
        require __DIR__.'/../../helpers.php';
        require_once APP_PATH.'modules/tags/hm-tags.php';
        require_once APP_PATH.'modules/tags/handler_modules.php';
        // Hm_Handler_process_tag_since_setting lives in output_modules.php.
        require_once APP_PATH.'modules/tags/output_modules.php';
        require_once APP_PATH.'modules/imap/functions.php';
    }

    /**
     * Prepares a Handler_Test and initializes the real Hm_Tags repository
     * against the same mock user_config/session the handler under test
     * will receive, so tests can seed tags before running the handler.
     */
    private function prep_tag_handler_test($handler_name, array $post = array(), array $input = array()) {
        $test = new Handler_Test($handler_name, 'tags');
        $test->post = $post;
        $test->input = $input;
        $test->prep();
        $hmod = new stdClass();
        $hmod->user_config = $test->module_exec->user_config;
        $hmod->session = $test->ses_obj;
        Hm_Tags::init($hmod);
        return $test;
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_mod_env_reports_supported_modules() {
        $test = new Handler_Test('mod_env', 'tags');
        $test->modules = array('imap', 'feeds');
        $res = $test->run();
        $this->assertEquals(array('imap', 'feeds'), array_values($res->handler_response['mod_support']));
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_tag_data_defaults_to_empty_list() {
        $test = new Handler_Test('tag_data', 'tags');
        $res = $test->run();
        $this->assertEquals(array(), $res->handler_response['tags']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_tag_data_returns_previously_configured_tags() {
        $test = new Handler_Test('tag_data', 'tags');
        $test->user_config = array('tags' => array(
            't1' => array('id' => 't1', 'name' => 'Work', 'parent' => null),
        ));
        $res = $test->run();
        $this->assertArrayHasKey('t1', $res->handler_response['tags']);
        $this->assertEquals('Work', $res->handler_response['tags']['t1']['name']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_update_creates_new_tag_with_color() {
        $test = $this->prep_tag_handler_test('process_tag_update', array(
            'tag_name' => 'Invoices',
            'parent_tag' => '',
            'tag_id' => '',
            'tag_color' => '#1a73e8',
        ));
        $res = $test->run_only();
        $this->assertTrue($res->handler_response['tag_success']);
        $this->assertEquals(array('Tag Created'), Hm_Msgs::get());

        $tags = Hm_Tags::getAll();
        $this->assertCount(1, $tags);
        $tag = array_values($tags)[0];
        $this->assertEquals('Invoices', $tag['name']);
        $this->assertEquals('#1a73e8', $tag['color']);
        $this->assertSame('', $tag['parent']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_update_sanitizes_color_outside_the_palette() {
        $test = $this->prep_tag_handler_test('process_tag_update', array(
            'tag_name' => 'Suspicious',
            'parent_tag' => '',
            'tag_id' => '',
            'tag_color' => 'javascript:alert(1)',
        ));
        $test->run_only();
        $tag = array_values(Hm_Tags::getAll())[0];
        $this->assertEquals(Hm_Tags::defaultColor(), $tag['color']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_update_edits_existing_tag_without_duplicating() {
        $test = $this->prep_tag_handler_test('process_tag_update', array(
            'tag_name' => 'Renamed',
            'parent_tag' => '',
            'tag_id' => 'tag1',
            'tag_color' => '#188038',
        ));
        Hm_Tags::add(array('id' => 'tag1', 'name' => 'Old Name', 'parent' => null, 'color' => '#d93025'));

        $res = $test->run_only();
        $this->assertTrue($res->handler_response['tag_success']);
        $this->assertEquals(array('Tag Edited'), Hm_Msgs::get());

        $this->assertCount(1, Hm_Tags::getAll());
        $tag = Hm_Tags::get('tag1');
        $this->assertEquals('Renamed', $tag['name']);
        $this->assertEquals('#188038', $tag['color']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_update_does_nothing_when_a_required_field_is_missing() {
        $test = $this->prep_tag_handler_test('process_tag_update', array(
            'tag_name' => 'Incomplete',
            'tag_id' => '',
            'tag_color' => '#1a73e8',
            // 'parent_tag' intentionally omitted
        ));
        $res = $test->run_only();
        $this->assertArrayNotHasKey('tag_success', $res->handler_response);
        $this->assertEquals(array(), Hm_Tags::getAll());
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_delete_removes_existing_tag() {
        $test = $this->prep_tag_handler_test('process_tag_delete', array(
            'tag_delete' => true,
            'tag_id' => 'tag1',
        ));
        Hm_Tags::add(array('id' => 'tag1', 'name' => 'Work'));

        $res = $test->run_only();
        $this->assertTrue($res->handler_response['tag_success']);
        $this->assertEquals(array('Tag Deleted'), Hm_Msgs::get());
        $this->assertFalse(Hm_Tags::get('tag1'));
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_delete_missing_id_adds_warning() {
        $test = $this->prep_tag_handler_test('process_tag_delete', array(
            'tag_delete' => true,
            'tag_id' => 'no_such_tag',
        ));
        $res = $test->run_only();
        $this->assertArrayNotHasKey('tag_success', $res->handler_response);
        $this->assertEquals(array('Tag ID not found'), Hm_Msgs::get());
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_imap_tag_content_noop_without_configured_sources() {
        $test = new Handler_Test('imap_tag_content', 'tags');
        $test->post = array('folder' => 'tag1');
        $res = $test->run();
        $this->assertArrayNotHasKey('imap_tag_data', $res->handler_response);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_source_max_setting_saves_value() {
        $test = new Handler_Test('process_tag_source_max_setting', 'tags');
        $test->post = array('save_settings' => '1', 'tag_per_source' => '25');
        $res = $test->run();
        $this->assertEquals(25, $res->handler_response['new_user_settings']['tag_per_source_setting']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_source_max_setting_falls_back_to_default_without_save() {
        $test = new Handler_Test('process_tag_source_max_setting', 'tags');
        $res = $test->run();
        $this->assertEquals(DEFAULT_TAGS_PER_SOURCE, $res->handler_response['user_settings']['tag_per_source']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_process_tag_since_setting_saves_value() {
        $test = new Handler_Test('process_tag_since_setting', 'tags');
        $test->post = array('save_settings' => '1', 'tag_since' => '-2 weeks');
        $res = $test->run();
        $this->assertEquals('-2 weeks', $res->handler_response['new_user_settings']['tag_since_setting']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_keyword_from_name_is_an_imap_atom() {
        $this->assertEquals('Gestita', Hm_Tags::keywordFromName('Gestita'));
        $this->assertEquals('Da_richiamare', Hm_Tags::keywordFromName('Da richiamare!'));
        $this->assertEquals('Preventivi_2026', Hm_Tags::keywordFromName('  Preventivi (2026) '));
        $this->assertEquals('Gestita_2', Hm_Tags::keywordFromName('Gestita', array(array('keyword' => 'Gestita'))));
        $this->assertMatchesRegularExpression('/^label_[0-9a-f]{6}$/', Hm_Tags::keywordFromName('***'));
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_tag_ids_from_flags_matches_keywords_only() {
        $this->prep_tag_handler_test('tag_data');
        Hm_Tags::add(array('id' => 'tag1', 'name' => 'Work', 'keyword' => 'Work'));
        Hm_Tags::add(array('id' => 'tag2', 'name' => 'Home', 'keyword' => 'Home'));
        $this->assertEquals(array('tag1'), Hm_Tags::tagIdsFromFlags('\\Seen \\Answered Work'));
        $this->assertEquals(array(), Hm_Tags::tagIdsFromFlags('\\Seen Workshop'));
        $this->assertEquals(array('tag1', 'tag2'), Hm_Tags::tagIdsFromFlags('Work Home \\Flagged'));
    }
}
