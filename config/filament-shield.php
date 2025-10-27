<?php

return [
    'shield_resource' => [
        'should_register_navigation' => true,
        'slug' => 'shield/roles',
        'navigation_sort' => -1,
        'navigation_badge' => true,
        'navigation_group' => true,
        'sub_navigation_position' => null,
        'is_globally_searchable' => false,
        'show_model_path' => true,
        'is_scoped_to_tenant' => true,
        'cluster' => null,
    ],

    'tenant_model' => null,

    'auth_provider_model' => [
        'fqcn' => 'App\\Models\\User',
    ],

    // 'super_admin' => [
    //     'enabled' => true,
    //     'name' => 'super_admin',
    //     // 'define_via_gate' => true, // Enforce permissions via gates
    //     // 'intercept_gate' => 'before', // after
    // ],

    'permission_prefixes' => [
        'resource' => [
            'view',
            'view_any',
            'create',
            'update',
            'reorder',
            'delete',

            
            'restore',
            'restore_any',
            'replicate',
            'delete_any',
            'force_delete',
            'force_delete_any',
        ],

        'page' => 'page',
        'widget' => 'widget',
        'cluster' => 'cluster',
    ],

    'entities' => [
        'pages' => true,
        'widgets' => true,
        'resources' => true,
        'custom_permissions' => true,
        'clusters' => true,
    ],

    'generator' => [
        'option' => 'policies_and_permissions',
        'policy_directory' => 'Policies',
        'policy_namespace' => 'Policies',
    ],

    'exclude' => [
        'enabled' => false,

        'pages' => [
            'Dashboard',
            'Settings',
        ],

        'widgets' => [
            'AccountWidget', 'FilamentInfoWidget',
        ],

        'clusters' => [

        ],

        'resources' => [
            // Ensure 'user', 'group', and 'member' are not excluded here
        ],
    ],

    'discovery' => [
        'discover_all_resources' => true, // Enable discovery of all resources
        //discover all relation managers
        'discover_all_relation_managers' => true,
        'discover_all_relation_resources' => true,
        'discover_all_widgets' => true,
        'discover_all_pages' => true,
        'discover_all_clusters' => true,
    ],

    'register_role_policy' => [
        'enabled' => true,
    ],

];