<?php
declare(strict_types=1);
namespace Neos\EventSourcing\EventListener;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\EventSourcing\EventListener\Exception\EventCouldNotBeAppliedException;
use Neos\EventSourcing\EventStore\EventEnvelope;
use Neos\EventSourcing\EventStore\EventStoreManager;
use Neos\EventSourcing\EventStore\Exception\EventStreamNotFoundException;
use Neos\EventSourcing\EventStore\StreamAwareEventListenerInterface;
use Neos\EventSourcing\EventStore\StreamName;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
final class EventListenerInvoker
{
    /**
     * @Flow\Inject
     * @var AppliedEventsLogRepository
     */
    protected $appliedEventsLogRepository;

    /**
     * @Flow\Inject
     * @var EventStoreManager
     */
    protected $eventStoreManager;

    /**
     * @param EventListenerInterface $listener
     * @param \Closure $progressCallback
     * @throws EventCouldNotBeAppliedException
     */
    public function catchUp(EventListenerInterface $listener, \Closure $progressCallback = null): void
    {
        $highestAppliedSequenceNumber = $this->appliedEventsLogRepository->reserveHighestAppliedEventSequenceNumber(get_class($listener));
        try {
            if ($listener instanceof StreamAwareEventListenerInterface) {
                $streamName = $listener::listensToStream();
            } else {
                $streamName = StreamName::all();
            }
            $eventStore = $this->eventStoreManager->getEventStoreForEventListener(get_class($listener));
            $eventStream = $eventStore->load($streamName, $highestAppliedSequenceNumber + 1);
            foreach ($eventStream as $eventEnvelope) {
                $this->applyEvent($listener, $eventEnvelope);
                $this->appliedEventsLogRepository->saveHighestAppliedSequenceNumber(get_class($listener), $eventEnvelope->getRawEvent()->getSequenceNumber());
                if ($progressCallback !== null) {
                    $progressCallback($eventEnvelope);
                }
            }
        } catch (EventStreamNotFoundException $exception) {
            // this is not an error
        } finally {
            $this->appliedEventsLogRepository->releaseHighestAppliedSequenceNumber();
        }
    }

    /**
     * @param EventListenerInterface $listener
     * @param EventEnvelope $eventEnvelope
     * @throws EventCouldNotBeAppliedException
     */
    private function applyEvent(EventListenerInterface $listener, EventEnvelope $eventEnvelope): void
    {
        $event = $eventEnvelope->getDomainEvent();
        $rawEvent = $eventEnvelope->getRawEvent();
        try {
            $listenerMethodName = 'when' . (new \ReflectionClass($event))->getShortName();
        } catch (\ReflectionException $exception) {
            throw new \RuntimeException(sprintf('Could not extract listener method name for listener %s and event %s', get_class($listener), get_class($event)), 1541003718, $exception);
        }
        if (!method_exists($listener, $listenerMethodName)) {
            return;
        }
        if ($listener instanceof BeforeInvokeInterface) {
            $listener->beforeInvoke($eventEnvelope);
        }
        try {
            $listener->$listenerMethodName($event, $rawEvent);
        } catch (\Exception $exception) {
            throw new EventCouldNotBeAppliedException(sprintf('Event "%s" (%s) could not be applied to %s. Sequence number (%d) is not updated', $rawEvent->getIdentifier(), $rawEvent->getType(), get_class($listener), $rawEvent->getSequenceNumber()), 1544207001, $exception, $eventEnvelope, $listener);
        }
        if ($listener instanceof AfterInvokeInterface) {
            $listener->afterInvoke($eventEnvelope);
        }
    }
}
