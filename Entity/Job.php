<?php

namespace Jerive\Bundle\SchedulerBundle\Entity;

use Jerive\Bundle\SchedulerBundle\Schedule\ScheduledServiceInterface;
use Jerive\Bundle\SchedulerBundle\Schedule\DelayedProxy;
use Jerive\Bundle\SchedulerBundle\Exception\FailedExecutionException;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks
 * @ORM\Entity(repositoryClass="Jerive\Bundle\SchedulerBundle\Entity\Repository\JobRepository")
 * @ORM\Table(indexes={
 *      @ORM\Index(columns={"status", "nextExecutionDate"})
 * })
 */
class Job
{
    const STATUS_WAITING    = 'waiting';

    const STATUS_PENDING    = 'pending';

    const STATUS_TERMINATED = 'terminated';

    const STATUS_FAILED     = 'waiting';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $name;

    /**
     * @ORM\Column(type="string")
     */
    protected $serviceId;

    /**
     * @var DelayedProxy
     *
     * @ORM\Column(type="object")
     */
    protected $proxy;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $nextExecutionDate;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $insertionDate;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $firstExecutionDate;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $lastExecutionDate;

    /**
     * A \DateInterval specification
     * http://www.php.net/manual/fr/dateinterval.construct.php
     *
     * @ORM\Column(type="string", nullable=true)
     */
    protected $repeatEvery;

    /**
     * @ORM\Column(type="integer")
     */
    protected $executionCount = 0;

    /**
     * @ORM\Column(type="string", length=10)
     */
    protected $status = self::STATUS_WAITING;

    /**
     * @var FailedExecutionException
     * @ORM\Column(type="object", nullable=true)
     */
    protected $lastException;

    /**
     * @var boolean
     */
    protected $locked = false;

    public function __construct()
    {
        $this->date = new \DateTime('now');
    }

    public function lock()
    {
        $this->locked = true;
    }

    public function unlock()
    {
        $this->locked = false;
    }

    public function checkUnlocked()
    {
        if ($this->locked) {
            throw new \RuntimeException('Cannot set values on a locked job');
        }
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set service
     *
     * @param string $service
     * @return Job
     */
    public function setServiceId($service)
    {
        $this->checkUnlocked();
        $this->serviceId = $service;

        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get service
     *
     * @return string
     */
    public function getServiceId()
    {
        return $this->serviceId;
    }

    public function prepareForExecution()
    {
        $this->status = self::STATUS_PENDING;
        $this->lastExecutionDate = new \DateTime('now');
    }

    /**
     * Set params
     *
     * @param string $params
     * @return Job
     */
    public function setProxy($proxy)
    {
        $this->checkUnlocked();
        $this->proxy = $proxy;

        return $this;
    }

    /**
     * Get proxy
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * @return DelayedProxy
     */
    public function program()
    {
        return $this->getProxy();
    }

    /**
     * @param ScheduledServiceInterface $service
     */
    public function execute(ScheduledServiceInterface $service)
    {
        if (!$this->status == self::STATUS_PENDING) {
            throw new \RuntimeException('Cannot execute a job in status other than pending');
        }

        $isFuture = $this->getNextExecutionDate() > new \DateTime('now');

        if (($this->executionCount && !$this->repeatEvery && $isFuture)
                || $isFuture && $this->repeatEvery) {
            if ($this->repeatEvery) {
                $this->status = self::STATUS_WAITING;
            }
        } else {
            $this->checkUnlocked();
            $this->lock();

            try {
                $service->setJob($this);
                $this->getProxy()->execute($service);
            } catch (\Exception $e) {
                $this->status = self::STATUS_FAILED;
                $this->executionCount++;
                $this->lastException = new FailedExecutionException($e->getMessage(), $e->getCode(), $e);
                throw $e;
            }

            $this->unlock();
            $this->executionCount++;

            if ($this->repeatEvery) {
                $this->nextExecutionDate =
                    clone $this->getNextExecutionDate()->add(new \DateInterval($this->repeatEvery))
                ;

                $this->execute($service);
            } else {
                $this->status = self::STATUS_TERMINATED;
                $this->nextExecutionDate = null;
                return;
            }
        }
    }

    /**
     * Get nextExecutionDate
     *
     * @return \DateTime
     */
    public function getNextExecutionDate()
    {
        return $this->nextExecutionDate;
    }

    /**
     *
     * @param string $intervalSpec
     * @return Job
     */
    public function setScheduledIn($intervalSpec)
    {
        $this->checkUnlocked();
        $this->nextExecutionDate = (new \DateTime('now'))->add(new \DateInterval($intervalSpec));
        $this->firstExecutionDate = $this->nextExecutionDate;
        return $this;
    }

    /**
     *
     * @param \DateTime $date
     * @return Job
     * @throws \RuntimeException
     */
    public function setScheduledAt(\DateTime $date)
    {
        $this->checkUnlocked();
        if ($this->executionCount) {
            throw new \RuntimeException('Cannot reset execution date');
        }

        $this->nextExecutionDate = $date;
        $this->firstExecutionDate = $date;
        return $this;
    }

    /**
     * Set repeatEvery
     *
     * @param string $repeatEvery
     * @return Job
     */
    public function setRepeatEvery($repeatEvery)
    {
        new \DateInterval($repeatEvery);
        $this->repeatEvery = $repeatEvery;

        return $this;
    }

    /**
     * Get repeatEvery
     *
     * @return string
     */
    public function getRepeatEvery()
    {
        return $this->repeatEvery;
    }

    /**
     * @ORM\PrePersist
     */
    public function prePersist()
    {
        $this->insertionDate = new \DateTime('now');

        if (!$this->firstExecutionDate) {
            $this->firstExecutionDate = $this->insertionDate;
            $this->nextExecutionDate = $this->insertionDate;
        }
    }

    /**
     * Get insertionDate
     *
     * @return \DateTime
     */
    public function getInsertionDate()
    {
        return $this->insertionDate;
    }

    /**
     * Get lastExecutionDate
     *
     * @return \DateTime
     */
    public function getLastExecutionDate()
    {
        return $this->lastExecutionDate;
    }

    /**
     * @return int
     */
    public function getExecutionCount()
    {
        return $this->executionCount;
    }

    public function endRepetition()
    {
        $this->repeatEvery = null;
        return $this;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }
}
