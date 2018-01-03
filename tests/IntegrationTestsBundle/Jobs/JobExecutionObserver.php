<?php

declare(strict_types=1);

namespace Akeneo\Test\IntegrationTestsBundle\Jobs;

use Akeneo\Bundle\BatchBundle\Job\JobInstanceRepository;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Utility class used to get observe the job executions.
 *
 * @author    Samir Boulil <samir.boulil@akeneo.com>
 * @copyright 2018 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class JobExecutionObserver
{
    /** @var JobInstanceRepository */
    private $jobInstanceRepository;

    /** EntityRepository */
    private $jobExecutionsRepository;

    /**
     * @param KernelInterface $kernel
     */
    public function __construct(KernelInterface $kernel)
    {
        $this->jobInstanceRepository = $kernel->getContainer()->get('pim_enrich.repository.job_instance');
        $this->jobExecutionsRepository = $kernel->getContainer()->get('pim_enrich.repository.job_execution');
    }

    public function jobExecutions(): array
    {
        return $this->jobExecutionsRepository->findAll();
    }

    public function jobExecutionsWithJobName(string $jobName): array
    {
        $jobInstance = $this->jobInstanceRepository->findOneBy(['code' => $jobName]);
        if (null === $jobInstance) {
            throw new \InvalidArgumentException(sprintf('No job instance found for job name "%s"', $jobName));
        }

        return $jobInstance->getJobExecutions()->toArray();
    }

    public function purge(string $jobName): void
    {
        $jobInstance = $this->get('pim_enrich.repository.job_instance')->findOneBy(['code' => $jobName]);
        if (null === $jobInstance) {
            throw new \InvalidArgumentException(sprintf('No job instance found for job name "%s"', $jobName));
        }

        $jobExecutions = $jobInstance->getJobExecutions();
        foreach ($jobExecutions as $jobExecution) {
            $jobInstance->removeJobExecution($jobExecution);
        }

        $this->get('akeneo_batch.saver.job_instance')->save($jobInstance);
    }
}
