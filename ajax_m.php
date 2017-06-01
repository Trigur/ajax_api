<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Image CMS
 *
 * Gallery main model
 */
class ajax_m extends CI_Model {

    public function __construct()
    {
        parent::__construct();
    }

    public function log($mark, $data)
    {
        $username = $this->dx_auth->get_username();
        if (! $username) {
            $username = $this->input->ip_address();
        }

        $insertData = [
            'ip_name' => $username,
            'mark'    => $mark,
            'data'    => $data,
        ];

        $this->db->insert('ajax_log', $insertData);
    }

    public function getPattern($mark)
    {
        try{
            $queryResult = $this->db->get_where('mod_email_paterns', ['name' => $mark])->row_array();
        }catch (Exception $e) {
            return false;
        }

        return isset($queryResult['name']) ? $queryResult['name'] : false;
    }

    public function install()
    {
        $this->load->dbforge();

        $fields = [
            'id'      => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => TRUE],
            'ip_name' => ['type' => 'VARCHAR', 'constraint' => 50],
            'mark'    => ['type' => 'VARCHAR', 'constraint' => 50],
            'data'    => ['type' => 'TEXT'],
        ];

        $this->dbforge->add_key('id', TRUE);
        $this->dbforge->add_field($fields);
        $this->dbforge->create_table('ajax_log', TRUE);

        $this->db
            ->where('name', 'ajax')
            ->update('components', [
                'autoload' => '1',
                'enabled' => '1',
                'in_menu' => 0
            ]);
    }

    public function deinstall()
    {
        $this->load->dbforge();
        $this->dbforge->drop_table('ajax_log');
    }
}