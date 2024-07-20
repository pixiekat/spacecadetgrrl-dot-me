<?php
namespace App\pages;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

$from = $app->getCurrentRequest()->server->get('MAILER_FROM');
$email = (new Email())
    ->from($from)
    ->to('calamitousgrace@gmail.com')
    ->subject('Your Subject')
    ->text('Hello, this is the plain text content.')
    ->html('<p>Hello, this is the HTML content.</p>');

// You can add more headers or attachments as needed
// $email->attachFromPath('/path/to/attachment.pdf');

// Send the email
try {
  $app->getMailer()->send($email);
}
catch (\Exception $e) {
}
echo $app->getTwig()->render('pages\homepage.html.twig', []);