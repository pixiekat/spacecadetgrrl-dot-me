<?php
namespace App\pages;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/*
$email = (new Email())
    ->from('katie@pixiekitten.net')
    ->to('calamitousgrace@gmail.com')
    ->subject('Your Subject')
    ->text('Hello, this is the plain text content.')
    ->html('<p>Hello, this is the HTML content.</p>');

// You can add more headers or attachments as needed
// $email->attachFromPath('/path/to/attachment.pdf');

// Send the email
$transport = Transport::fromDsn($request->server->get('MAILER_DSN'));
$mailer = new Mailer($transport);
$mailer->send($email);*/

echo $app->getTwig()->render('pages\homepage.html.twig', []);