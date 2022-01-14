<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\EventListener;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Event\GetStatDataEvent;
use Mautic\LeadBundle\EventListener\SegmentStatsSubscriber;
use Mautic\LeadBundle\LeadEvents;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

class SegmentStatsSubscriberTest extends MauticMysqlTestCase
{
    /**
     * @var SegmentStatsSubscriber
     */
    private $subscriber;

    /**
     * @throws \Exception
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->subscriber       = new SegmentStatsSubscriber($this->em);
    }

    /**
     * Test SubscribedEvents.
     */
    public function testGetSubscribedEvents(): void
    {
        Assert::assertArrayHasKey(LeadEvents::LEAD_LIST_STAT, SegmentStatsSubscriber::getSubscribedEvents());
    }

    public function testGetCampaignEntryPoints(): void
    {
        $campaign = $this->createCampaignWithLeadList();
        $event    = new GetStatDataEvent();

        $this->subscriber->getStatsLeadEvents($event);

        $this->assertTrue(
            in_array(
                $event->getResults()['segments'][0]['item_id'],
                array_map(function ($list) {
                    return $list->getId();
                }, $campaign->getLists()->toArray())
            )
        );

        $this->assertSame(1, (int) $event->getResults()['segments'][0]['is_used']);
        $this->assertSame(1, (int) $event->getResults()['segments'][0]['is_published']);
    }

    public function testGetCampaignChangeSegmentAction(): void
    {
        $campaign = $this->createCampaignChangeListEvent();

        $event   = new GetStatDataEvent();

        $this->subscriber->getStatsLeadEvents($event);

        foreach ($event->getResults()['segments'] as $segment) {
            $this->assertTrue(
                in_array(
                    $segment['item_id'],
                    array_merge(
                        $campaign->getEvents()[0]->getProperties()['addToLists'],
                        $campaign->getEvents()[0]->getProperties()['removeFromLists']
                    )
                )
            );
            $this->assertSame(1, (int) $segment['is_used']);
            $this->assertSame(1, (int) $segment['is_published']);
        }
    }

    public function testGetEmailIncludeExcludeList(): void
    {
        $email = $this->createEmailWithIncludedListsExcludedLists();

        $event   = new GetStatDataEvent();

        $this->subscriber->getStatsLeadEvents($event);

        foreach ($event->getResults()['segments'] as $segment) {
            $this->assertTrue(
                in_array(
                    $segment['item_id'],
                    [
                        $email->getExcludedLists()->toArray()[0]->getId(),
                        $email->getLists()->toArray()[0]->getId(),
                    ]
                )
            );
            $this->assertSame(1, (int) $segment['is_used']);
            $this->assertSame(1, (int) $segment['is_published']);
        }
    }

    public function testGetFilterSegmentsAction(): void
    {
        $segment = $this->createSegmentWithFilter();

        $event   = new GetStatDataEvent();

        $this->subscriber->getStatsLeadEvents($event);

        $this->assertSame(
            $segment->getFilters()[0]['properties']['filter'][0],
            (int) $event->getResults()['segments'][0]['item_id']
        );
        $this->assertSame(1, (int) $event->getResults()['segments'][0]['is_used']);
        $this->assertSame(1, (int) $event->getResults()['segments'][0]['is_published']);

        $this->assertSame($segment->getId(), (int) $event->getResults()['segments'][1]['item_id']);
        $this->assertNull($event->getResults()['segments'][1]['is_used']);
        $this->assertSame(1, (int) $event->getResults()['segments'][1]['is_published']);
    }

    private function createCampaignWithLeadList(): Campaign
    {
        $segmentName = 'Segment For Campaign';
        $segment     = new LeadList();
        $segment->setName($segmentName);
        $segment->setAlias(mb_strtolower($segmentName));
        $segment->setIsPublished(true);
        $this->em->persist($segment);
        $this->em->flush();

        $campaign = new Campaign();
        $campaign->setName('Campaign With LeadList');
        $campaign->addList($segment);

        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function createCampaignChangeListEvent(): Campaign
    {
        $segmentName         = 'addToLists Segment';
        $includedSegment     = new LeadList();
        $includedSegment->setName($segmentName);
        $includedSegment->setAlias(mb_strtolower($segmentName));
        $includedSegment->setIsPublished(true);
        $this->em->persist($includedSegment);
        $this->em->flush();

        $segmentName         = 'removeFromLists Segment';
        $excludedSegment     = new LeadList();
        $excludedSegment->setName($segmentName);
        $excludedSegment->setAlias(mb_strtolower($segmentName));
        $excludedSegment->setIsPublished(true);
        $this->em->persist($excludedSegment);
        $this->em->flush();

        $campaign = new Campaign();
        $campaign->setName('Campaign With LeadList');
        $this->em->persist($campaign);
        $this->em->flush();

        $event = new Event();
        $event->setName('Modify contacts segments');
        $event->setType('lead.changelist');
        $event->setEventType('action');
        $event->setTempId('tempid');
        $event->setProperties([
            'addToLists'      => [$includedSegment->getId()],
            'removeFromLists' => [$excludedSegment->getId()],
        ]);
        $event->setCampaign($campaign);
        $this->em->persist($event);
        $this->em->flush();

        $campaign->addEvent(0, $event);

        $this->em->persist($event);
        $this->em->flush();

        return $campaign;
    }

    private function createEmailWithIncludedListsExcludedLists(): Email
    {
        $segmentName         = 'Included Segment';
        $includedSegment     = new LeadList();
        $includedSegment->setName($segmentName);
        $includedSegment->setAlias(mb_strtolower($segmentName));
        $includedSegment->setIsPublished(true);
        $this->em->persist($includedSegment);
        $this->em->flush();

        $segmentName         = 'Excluded Segment';
        $excludedSegment     = new LeadList();
        $excludedSegment->setName($segmentName);
        $excludedSegment->setAlias(mb_strtolower($segmentName));
        $excludedSegment->setIsPublished(true);
        $this->em->persist($excludedSegment);
        $this->em->flush();

        $email = new Email();
        $email->setName('Email 1');
        $email->setSubject('Subject 1');
        $email->setUuid(Uuid::uuid4()->toString());
        $email->setDateAdded(new \DateTime());
        $email->setPublicPreview(true);
        $email->setCustomHtml(json_encode(''));
        $email->setEmailType('list');

        $email->addList($includedSegment);
        $email->addExcludedList($excludedSegment);

        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function createSegmentWithFilter(): LeadList
    {
        $segmentName          = 'Segment For Filter';
        $segmentForFilter     = new LeadList();
        $segmentForFilter->setName($segmentName);
        $segmentForFilter->setAlias(mb_strtolower($segmentName));
        $segmentForFilter->setIsPublished(true);
        $this->em->persist($segmentForFilter);
        $this->em->flush();

        $segmentName = 'Segment With Filter';
        $segment     = new LeadList();
        $segment->setName($segmentName);
        $segment->setAlias(mb_strtolower($segmentName));
        $segment->setIsPublished(true);
        $segment->setFilters([['field' => 'leadlist', 'type' => 'leadlist', 'properties' => ['filter' => [$segmentForFilter->getId()]]]]);
        $this->em->persist($segment);
        $this->em->flush();

        return $segment;
    }
}
