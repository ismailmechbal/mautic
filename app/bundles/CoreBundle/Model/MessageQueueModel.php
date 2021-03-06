<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Model;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Entity\MessageQueue;
use Mautic\CoreBundle\Event\MessageQueueBatchProcessEvent;
use Mautic\CoreBundle\Event\MessageQueueEvent;
use Mautic\CoreBundle\Event\MessageQueueProcessEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class MessageQueueModel.
 */
class MessageQueueModel extends FormModel
{
    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var CompanyModel
     */
    protected $companyModel;

    /**
     * MessageQueueModel constructor.
     *
     * @param LeadModel    $leadModel
     * @param CompanyModel $companyModel
     */
    public function __construct(LeadModel $leadModel, CompanyModel $companyModel)
    {
        $this->leadModel    = $leadModel;
        $this->companyModel = $companyModel;
    }

    /**
     * @return \Doctrine\ORM\EntityRepository|\Mautic\CoreBundle\Entity\MessageQueueRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticCoreBundle:MessageQueue');
    }

    /**
     * @param       $leads
     * @param       $channel
     * @param       $channelId
     * @param null  $scheduledInterval
     * @param int   $maxAttempts
     * @param int   $priority
     * @param null  $campaignEventId
     * @param array $options
     *
     * @return bool
     */
    public function addToQueue($leads, $channel, $channelId, $scheduledInterval = null, $maxAttempts = 1, $priority = 1, $campaignEventId = null, $options = [])
    {
        $messageQueues = [];

        if (!$scheduledInterval) {
            $scheduledDate = new \DateTime();
        } elseif (!$scheduledInterval instanceof \DateTime) {
            $scheduledInterval = (('H' === $scheduledInterval) ? 'PT' : 'P').$scheduledInterval;
            $scheduledDate     = (new \DateTime())->add(new \DateInterval($scheduledInterval));
        }

        foreach ($leads as $lead) {
            if (empty($this->getRepository()->findMessage($channel, $channelId, $lead['id']))) {
                $leadEntity   = $this->leadModel->getEntity($lead['id']);
                $messageQueue = new MessageQueue();
                if ($campaignEventId) {
                    $messageQueue->setEvent($this->em->getReference('MauticCampaignBundle:Event', $campaignEventId));
                }
                $messageQueue->setChannel($channel);
                $messageQueue->setChannelId($channelId);
                $messageQueue->setDatePublished(new \DateTime());
                $messageQueue->setMaxAttempts($maxAttempts);
                $messageQueue->setLead($leadEntity);
                $messageQueue->setPriority($priority);
                $messageQueue->setScheduledDate($scheduledDate);
                $messageQueue->setOptions($options);

                $messageQueues[] = $messageQueue;
            }
        }

        if ($messageQueues) {
            $this->saveEntities($messageQueues);
            $this->em->clear(MessageQueue::class);
        }

        return true;
    }

    /**
     * @param null $channel
     * @param null $channelId
     *
     * @return int
     */
    public function sendMessages($channel = null, $channelId = null)
    {
        // Note when the process started for batch purposes
        $processStarted = new \DateTime();
        $limit          = 50;
        $counter        = 0;
        while ($queue = $this->getRepository()->getQueuedMessages($limit, $processStarted, $channel, $channelId)) {
            $counter += $this->processMessageQueue($queue);

            // Remove the entities from memory
            $this->em->clear(MessageQueue::class);
            $this->em->clear(Lead::class);
        }

        return $counter;
    }

    /**
     * @param $queue
     *
     * @return int
     */
    public function processMessageQueue($queue)
    {
        if (!is_array($queue)) {
            if (!$queue instanceof MessageQueue) {
                throw new \InvalidArgumentException('$queue must be an instance of '.MessageQueue::class);
            }

            $queue = [$queue->getId() => $queue];
        }

        $counter   = 0;
        $contacts  = [];
        $byChannel = [];

        // Lead entities will not have profile fields populated due to the custom field use - therefore to optimize resources,
        // get a list of leads to fetch details all at once along with company details for dynamic email content, etc
        /** @var MessageQueue $message */
        foreach ($queue as $message) {
            $contacts[$message->getId()] = $message->getLead()->getId();
        }
        $contactData = $this->leadModel->getRepository()->getContacts($contacts);
        $companyData = $this->companyModel->getRepository()->getCompaniesForContacts($contacts);
        foreach ($contacts as $messageId => $contactId) {
            $contactData[$contactId]['companies'] = $companyData[$contactId];
            $queue[$messageId]->getLead()->setFields($contactData[$contactId]);
        }

        // Group queue by channel and channel ID - this make it possible for processing listeners to batch process such as
        // sending emails in batches to 3rd party transactional services via HTTP APIs
        foreach ($queue as $key => $message) {
            if (MessageQueue::STATUS_SENT == $message->getStatus()) {
                unset($queue[$key]);
                continue;
            }

            $messageChannel   = $message->getChannel();
            $messageChannelId = $message->getChannelId();
            if (!$messageChannelId) {
                $messageChannelId = 0;
            }

            if (!isset($byChannel[$messageChannel])) {
                $byChannel[$messageChannel] = [];
            }
            if (!isset($byChannel[$messageChannel][$messageChannelId])) {
                $byChannel[$messageChannel][$messageChannelId] = [];
            }

            $byChannel[$messageChannel][$messageChannelId][] = $message;
        }

        // First try to batch process each channel
        foreach ($byChannel as $messageChannel => $channelMessages) {
            foreach ($channelMessages as $messageChannelId => $messages) {
                $event  = new MessageQueueBatchProcessEvent($messages, $messageChannel, $messageChannelId);
                $ignore = null;
                $this->dispatchEvent('process_batch_message_queue', $ignore, false, $event);
            }
        }
        unset($byChannel);

        // Now check to see if the message was processed by the listener and if not
        // send it through a single process event listener
        foreach ($queue as $message) {
            if (!$message->isProcessed()) {
                $event = new MessageQueueProcessEvent($message);
                $this->dispatchEvent('process_message_queue', $message, false, $event);
            }

            if ($message->isSuccess()) {
                ++$counter;
                $message->setSuccess();
                $message->setLastAttempt(new \DateTime());
                $message->setDateSent(new \DateTime());
                $message->setStatus(MessageQueue::STATUS_SENT);
            } elseif ($message->isFailed()) {
                // Failure such as email delivery issue or something so retry in a short time
                $this->rescheduleMessage($message, '15M');
            } // otherwise assume the listener did something such as rescheduling the message
        }

        //add listener
        $this->saveEntities($queue);

        return $counter;
    }

    /**
     * @param        $message
     * @param string $rescheduleInterval
     * @param null   $leadId
     * @param null   $channel
     * @param null   $channelId
     * @param bool   $persist
     */
    public function rescheduleMessage($message, $rescheduleInterval = null, $leadId = null, $channel = null, $channelId = null, $persist = false)
    {
        if (!$message instanceof MessageQueue && $leadId && $channel && $channelId) {
            $message = $this->getRepository()->findMessage($channel, $channelId, $leadId);
            $persist = true;
        }

        if ($message) {
            $message->setAttempts($message->getAttempts() + 1);
            $message->setLastAttempt(new \DateTime());
            $rescheduleTo = clone $message->getScheduledDate();

            if (null == $rescheduleInterval) {
                $rescheduleInterval = 'PT15M';
            } else {
                $rescheduleInterval = (('H' === $rescheduleInterval) ? 'PT' : 'P').$rescheduleInterval;
            }

            $rescheduleTo->add(new \DateInterval($rescheduleInterval));
            $message->setScheduledDate($rescheduleTo);
            $message->setStatus(MessageQueue::STATUS_RESCHEDULED);

            if ($persist) {
                $this->saveEntity($message);
            }

            // Mark as processed for listeners
            $message->setProcessed();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $entity
     * @param $isNew
     * @param $event
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null)
    {
        switch ($action) {
            case 'process_message_queue':
                $name = CoreEvents::PROCESS_MESSAGE_QUEUE;
                break;
            case 'process_batch_message_queue':
                $name = CoreEvents::PROCESS_MESSAGE_QUEUE_BATCH;
                break;
            case 'post_save':
                $name = CoreEvents::MESSAGE_QUEUED;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new MessageQueueEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }
            $this->dispatcher->dispatch($name, $event);

            return $event;
        } else {
            return null;
        }
    }
}
