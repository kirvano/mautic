<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Mailer\Transport;

use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mailer\SentMessage;

use Aws\Sqs\SqsClient;

class SqsCourierTransport extends AbstractTransport
{

  private $sqsClient;
  private $queueUrl;

  public function __construct()
  {
    $this->sqsClient = new SqsClient([
      'region'      => 'us-east-1',
      'version'     => 'latest',
      'credentials' => [
        'key'    => $_SERVER['AWS_SQS_ACCESS_KEY_ID'],
        'secret' => $_SERVER['AWS_SQS_SECRET_ACCESS_KEY'],
      ],
    ]);

    $this->queueUrl = $_SERVER['COURIER_QUEUE_NAME'];
  }

  protected function doSend(SentMessage $message): void
  {
    if (!$this->queueUrl) {
      throw new \Exception('Queue URL não configurada corretamente.');
    }

    $email = MessageConverter::toEmail($message->getOriginalMessage());

    $recipients = $email->getTo();

    if (empty($recipients)) {
      throw new \Exception('Nenhum destinatário encontrado.');
    }

    $subject = $email->getSubject();
    $text = $email->getTextBody() ?? $email->getHtmlBody() ?? '';
    $utf8Text = mb_convert_encoding($text, 'UTF-8', 'auto');
    $body = base64_encode($utf8Text);


    $sqsMessages = [];

    foreach ($recipients as $index => $recipient) {

      $data = [
        'to' => $recipient->getAddress(),
        'subject' => $subject,
        'body' => $body,
      ];

      $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

      $sqsMessages[] = [
        'Id'          => (string)$index,
        'MessageBody' => $jsonPayload,
      ];

      if (count($sqsMessages) === 10) {
        $this->sendBatch($sqsMessages);
        $sqsMessages = [];
      }
    }

    if (!empty($sqsMessages)) {
      $this->sendBatch($sqsMessages);
    }
  }

  private function sendBatch(array $messages): void
  {
    try {
      $this->sqsClient->sendMessageBatch([
        'QueueUrl' => $this->queueUrl,
        'Entries'  => $messages,
      ]);
    } catch (\Exception  $e) {
      throw new \Exception('Erro ao enviar mensagens para SQS: ' . $e->getMessage());
    }
  }

  public function __toString(): string
  {
    return 'courier';
  }
}
