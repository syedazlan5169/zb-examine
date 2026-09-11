<?php

return [
    'navigation' => 'User Management',
    'title' => 'Users',
    'create' => 'Create user',
    'edit' => 'Edit user',
    'reset_password' => 'Reset password',
    'name' => 'Name',
    'email' => 'Email',
    'role' => 'Role',
    'roles' => [
        'agent' => 'Agent',
        'officer' => 'Officer',
        'admin' => 'Administrator',
    ],
    'active' => 'Active',
    'inactive' => 'Inactive',
    'search' => 'Search',
    'filter' => 'Filter',
    'save' => 'Save',
    'created' => 'User created successfully.',
    'updated' => 'User updated successfully.',
    'password_reset' => 'Password reset successfully.',
    'errors' => [
        'self_demotion' => 'You cannot demote your own administrator account.',
        'self_deactivation' => 'You cannot deactivate your own account.',
        'last_active_admin' => 'At least one active administrator must remain.',
        'admin_self_reset' => 'Use My Account to change your own password.',
        'admin_required' => 'An active administrator is required for this action.',
    ],
];
