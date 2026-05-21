<?php return array(
    'root' => array(
        'name' => 'wordcamp/wcpt',
        'pretty_version' => 'dev-prototype/wp7-mcp-vetting',
        'version' => 'dev-prototype/wp7-mcp-vetting',
        'reference' => '4cea366236258502a4c598c15634fb845511118b',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'wordcamp/wcpt' => array(
            'pretty_version' => 'dev-prototype/wp7-mcp-vetting',
            'version' => 'dev-prototype/wp7-mcp-vetting',
            'reference' => '4cea366236258502a4c598c15634fb845511118b',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'wordpress/mcp-adapter' => array(
            'pretty_version' => 'dev-trunk',
            'version' => 'dev-trunk',
            'reference' => '7cc42a0c1de1937bea6ca9cea56d1b0818e94632',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../wordpress/mcp-adapter',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'wordpress/php-mcp-schema' => array(
            'pretty_version' => 'v0.1.1',
            'version' => '0.1.1.0',
            'reference' => 'e2118ec421ead51a3e17a3d8160f4537727e91b9',
            'type' => 'library',
            'install_path' => __DIR__ . '/../wordpress/php-mcp-schema',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
