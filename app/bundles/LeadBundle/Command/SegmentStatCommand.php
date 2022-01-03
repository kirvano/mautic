<?php

namespace Mautic\LeadBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Mautic\LeadBundle\Event\GetStatDataEvent;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SegmentStatCommand extends ModeratedCommand
{
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('mautic:segments:stat')
            ->setDescription('Gather Segment Statistics');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $event = new GetStatDataEvent();
        $this->dispatcher->dispatch(LeadEvents::LEAD_STAT, $event);

        dd($event->getResults());
    }
}
