<?php
/**
 * ReliableSite module welcome-email templates, surfaced by getEmailTemplate().
 *
 * Available tags include {service.reliablesite_username},
 * {service.reliablesite_server_id}, {service.reliablesite_server_label},
 * {package.reliablesite_product_id} and the standard {client.*} tags.
 */
Configure::set('Reliablesite.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => 'Thank you for your dedicated server order!

Your ReliableSite account username is: {service.reliablesite_username}

Your order has been received and our team is preparing a server for you. As
soon as a server has been assigned you will be able to manage it - power
controls, OS reinstall, KVM/IPMI, reverse DNS, backups, bandwidth graphs and
DDoS protection - from the service page in your client area.

You will receive a follow-up once your server is online.',
        'html' => '<p>Thank you for your dedicated server order!</p>
<p>Your ReliableSite account username is: <strong>{service.reliablesite_username}</strong></p>
<p>Your order has been received and our team is preparing a server for you. As soon as a server has been assigned you will be able to manage it - power controls, OS reinstall, KVM/IPMI, reverse DNS, backups, bandwidth graphs and DDoS protection - from the service page in your client area.</p>
<p>You will receive a follow-up once your server is online.</p>'
    ]
]);
