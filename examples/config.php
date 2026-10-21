<?php

/**
 * Merge these options into Application/Common/Conf/config.php.
 *
 * KDB_MODE is optional. Omit it or use auto to detect database_mode after
 * connecting; mysql, oracle and sqlserver remain available as checked hints.
 * KDB_SCHEMA is optional.
 */
return array(
    'DB_TYPE'       => 'kingbase',
    'DB_HOST'       => getenv('KDB_HOST') ?: '127.0.0.1',
    'DB_PORT'       => getenv('KDB_PORT'),
    'DB_NAME'       => getenv('KDB_DBNAME'),
    'DB_USER'       => getenv('KDB_USER'),
    'DB_PWD'        => getenv('KDB_PASSWORD'),
    'DB_CHARSET'    => 'utf8',
    'DB_PARAMS'     => array(),
    'DB_DEBUG'      => false,
    'DB_KDB_MODE'   => getenv('KDB_MODE') ?: 'auto',
    'DB_SCHEMA'     => getenv('KDB_SCHEMA') ?: '',

    // Oracle table-to-sequence mapping example:
    // 'DB_KDB_SEQUENCES' => array(
    //     'user' => 'user_seq',
    // ),
);
