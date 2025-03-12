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

    $toArray = $email->getTo();
    $to = null;
    if (!empty($toArray)) {
      $firstAddress = reset($toArray);
      $to = $firstAddress->getAddress();
    }

    $subject = $email->getSubject();

    $text = $email->getTextBody() ?? $email->getHtmlBody() ?? '';
    $utf8Text = mb_convert_encoding($text, 'UTF-8', 'auto');
    $body = base64_encode($utf8Text);

    $data = [
      'to' => $to,
      'subject' => $subject,
      'body' => $body,
    ];

    $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

    try {
      $result = $this->sqsClient->sendMessage([
        'QueueUrl'    => $this->queueUrl,
        'MessageBody' => $jsonPayload,
      ]);
    } catch (\Exception  $e) {
      throw new \Exception('Erro ao enviar mensagem para SQS: ' . $e->getMessage());
    }
  }

  public function __toString(): string
  {
    return 'courier';
  }
}
