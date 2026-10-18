<?php

declare(strict_types=1);

return [
    'roles' => [
        'owner' => ['label' => 'Owner', 'description' => 'Full access, including billing, ownership and deleting the team.'],
        'admin' => ['label' => 'Admin', 'description' => 'Manages members, invitations and team settings.'],
        'member' => ['label' => 'Member', 'description' => 'Can view, create and edit the team\'s work.'],
        'viewer' => ['label' => 'Viewer', 'description' => 'Read-only access to the team\'s work.'],
    ],

    'personal_team' => [
        'name' => ':name\'s Team',
        'fallback' => 'Personal Team',
    ],

    'create' => [
        'label' => 'Create team',
        'description' => 'A team is a shared workspace. You will be its owner and can invite people next.',
    ],

    'settings' => [
        'label' => 'Team settings',
        'tab' => 'Settings',
        'profile_heading' => 'Team profile',
        'profile_description' => 'The team\'s name, logo and the address it lives at.',
        'auto_join_heading' => 'Domain auto-join',
        'auto_join_description' => 'People who sign up with a verified address at this domain join the team as :role.',
        'saved' => 'Team settings saved',
    ],

    'members' => [
        'label' => 'Members',
        'title' => 'Team members',
        'count' => '{0} No members|{1} :count member|[2,*] :count members',
        'you' => '(you)',
        'change_role' => 'Change role',
        'change_role_heading' => 'Change the role of :name',
        'role_updated' => ':name is now :role',
        'remove' => 'Remove from team',
        'remove_heading' => 'Remove :name from the team?',
        'remove_description' => 'They lose access to the team right away. You can invite them again later.',
        'removed' => ':name was removed from the team',
    ],

    'invitations' => [
        'label' => 'Invitations',
        'title' => 'Pending invitations',
        'description' => 'Invitations that haven\'t been accepted yet.',
        'invite' => 'Invite member',
        'invite_heading' => 'Invite a member',
        'invite_description' => 'We\'ll e-mail them a link to join the team.',
        'send' => 'Send invitation',
        'sent' => 'Invitation sent to :email',
        'invited_by' => 'Invited by :name',
        'status' => [
            'pending' => 'Pending',
            'expired' => 'Expired',
        ],
        'never' => 'Never',
        'resend' => 'Resend invitation',
        'resent' => 'Invitation sent again to :email',
        'revoke' => 'Revoke invitation',
        'revoke_heading' => 'Revoke the invitation to :email?',
        'revoke_description' => 'The link in their e-mail will stop working.',
        'revoked' => 'Invitation to :email revoked',
        'empty_heading' => 'No pending invitations',
        'empty_description' => 'Invite people by e-mail to work with you in this team.',
    ],

    'seats' => [
        'remaining' => '{0} No seats left: remove a member or add seats to invite more people.|{1} :count seat left.|[2,*] :count seats left.',
        'used' => ':used of :seats seats used',
    ],

    'fields' => [
        'name' => 'Team name',
        'slug' => 'URL',
        'slug_help' => 'Lowercase letters, numbers and dashes. Changing it changes the team\'s links.',
        'logo' => 'Logo',
        'auto_join_domain' => 'E-mail domain',
        'auto_join_domain_help' => 'Your own e-mail domain. Leave empty to turn auto-join off.',
        'email' => 'E-mail address',
        'role' => 'Role',
        'avatar' => 'Avatar',
        'member' => 'Member',
        'joined' => 'Joined',
        'status' => 'Status',
        'expires' => 'Expires',
        'sent' => 'Sent',
    ],

    'leave' => [
        'heading' => 'Leave team',
        'description' => 'Stop being a member of this team. The last owner has to transfer ownership first.',
        'action' => 'Leave team',
        'confirm_heading' => 'Leave :team?',
        'confirm_description' => 'You lose access to the team until someone invites you again.',
        'done' => 'You left the team',
    ],

    'transfer' => [
        'heading' => 'Transfer ownership',
        'description' => 'Make another member the owner. You stay in the team as an admin.',
        'action' => 'Transfer ownership',
        'confirm_description' => 'The new owner gets full control of the team, including deleting it.',
        'submit' => 'Transfer',
        'new_owner' => 'New owner',
        'done' => ':name is now the owner',
    ],

    'delete' => [
        'heading' => 'Delete team',
        'description' => 'Permanently delete the team, its members and invitations. This can\'t be undone.',
        'action' => 'Delete team',
        'confirm_heading' => 'Delete :team?',
        'confirm_description' => 'Everyone loses access and the team is deleted permanently.',
        'type_name' => 'Type ":team" to confirm',
        'name_mismatch' => 'The name doesn\'t match.',
        'done' => 'Team deleted',
    ],

    'accept' => [
        'title' => 'Join :team',
        'description' => 'You have been invited to join :team as :role.',
        'description_by' => ':inviter invited you to join :team as :role.',
        'wrong_user' => 'You are signed in as :email. Sign in with the address the invitation was sent to.',
        'accept' => 'Accept invitation',
        'decline' => 'Decline',
        'decline_heading' => 'Decline the invitation to :team?',
        'switch_account' => 'Sign in with another account',
        'joined' => 'Welcome to :team',
        'declined' => 'Invitation declined',
    ],

    'mail' => [
        'subject' => 'You have been invited to join :team',
        'greeting' => 'Hello!',
        'intro' => 'You have been invited to join the :team team as :role.',
        'intro_by' => ':inviter invited you to join the :team team as :role.',
        'action' => 'Accept invitation',
        'account' => 'No account yet? You can create one after clicking the button.',
        'expires' => 'This invitation expires on :date.',
        'ignore' => 'If you weren\'t expecting this invitation, you can ignore this e-mail.',
    ],

    'errors' => [
        'forbidden' => 'Your role in this team doesn\'t allow this.',
        'owners_only' => 'Only team owners can do this.',
        'personal_team' => 'Your personal team can\'t be deleted.',
        'transfer_to_self' => 'You already own this team.',
        'not_a_member' => 'This person isn\'t a member of the team.',
        'unknown_role' => 'This role doesn\'t exist.',
        'seats_full' => 'The team has no seats left.',
        'own_role' => 'You can\'t change your own role.',
        'last_owner' => 'The team needs at least one owner.',
        'last_owner_leave' => 'You are the last owner: transfer ownership or delete the team first.',
        'already_member' => 'This person is already a member of the team.',
        'already_invited' => 'This address has already been invited.',
        'invitation_limit' => 'The team can\'t have more than :limit pending invitations.',
        'resend_throttled' => 'The invitation was just sent. Try again in :seconds seconds.',
        'invitation_invalid' => 'This invitation is no longer valid.',
        'invitation_expired' => 'This invitation has expired. Ask for a new one.',
        'invitation_wrong_user' => 'This invitation was sent to another e-mail address.',
        'domain_invalid' => 'Enter a domain like example.com.',
        'domain_public' => 'Public e-mail providers can\'t be used for auto-join.',
        'domain_not_yours' => 'Use the domain of your own verified e-mail address.',
    ],

    'mail_templates' => [
        'group' => 'Teams',
        'invitation' => 'Team invitation',
    ],
];
