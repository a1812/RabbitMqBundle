<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\RabbitMq\BaseConsumer as Consumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseConsumerCommand extends BaseRabbitMqCommand
{
    protected $consumer;

    /** @var int */
    protected $amount;

    abstract protected function getConsumerService();

    public function stopConsumer()
    {
        if ($this->consumer instanceof Consumer) {
            // Process current message, then halt consumer
            $this->consumer->forceStopConsumer();

            // Halt consumer if waiting for a new message from the queue
            try {
                $this->consumer->stopConsuming();
            } catch (AMQPTimeoutException $e) {
            }
        }
    }

    public function restartConsumer()
    {
        // TODO: Implement restarting of consumer
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Messages to consume', '0')
            ->addOption('route', 'r', InputOption::VALUE_OPTIONAL, 'Routing Key', '')
            ->addOption('memory-limit', 'l', InputOption::VALUE_OPTIONAL, 'Allowed memory for this process (MB)')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            ->addOption('without-signals', 'w', InputOption::VALUE_NONE, 'Disable catching of system signals')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->amount = (int)$input->getOption('messages');
        if (0 > $this->amount) {
            throw new \InvalidArgumentException("The -m option should be null or greater than 0");
        }
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \InvalidArgumentException When the number of messages to consume is less than 0
     * @throws \BadFunctionCallException When the pcntl is not installed and option -s is true
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (defined('AMQP_WITHOUT_SIGNALS') === false) {
            define('AMQP_WITHOUT_SIGNALS', $input->getOption('without-signals'));
        }

        if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl')) {
            if (!function_exists('pcntl_signal')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called.");
            }

            pcntl_signal(SIGTERM, [&$this, 'stopConsumer']);
            pcntl_signal(SIGINT, [&$this, 'stopConsumer']);
            pcntl_signal(SIGHUP, [&$this, 'restartConsumer']);
        }

        if (defined('AMQP_DEBUG') === false) {
            define('AMQP_DEBUG', (bool) $input->getOption('debug'));
        }

        $this->initConsumer($input);

        return $this->consumer->consume($this->amount);
    }

    protected function initConsumer(InputInterface $input)
    {
        $this->consumer = $this->getContainer()
                ->get(sprintf($this->getConsumerService(), $input->getArgument('name')));

        if ($input->hasOption('memory-limit')) {
            $memoryLimit = (int)$input->getOption('memory-limit');
            if ($memoryLimit > 0) {
                $this->consumer->setMemoryLimit($memoryLimit);
            }
        }
        $this->consumer->setRoutingKey($input->getOption('route'));
    }
}
