<?php

namespace Scp\Whmcs\Whmcs;
use Scp\Whmcs\Server\Provision\ServerProvisioner;
use Scp\Whmcs\Server\Usage\UsageUpdater;
use Scp\Whmcs\Server\ServerService;
use Scp\Whmcs\LogFactory;
use Scp\Whmcs\Whmcs\WhmcsConfig;
use Scp\Whmcs\Ticket\TicketManager;

/**
 * Class Responsibilities:
 *  - Respond to internal WHMCS events by routing them into proper handlers.
 */
class WhmcsEvents
{
    // The internal WHMCS names of events.
    // TODO: move to interface

    /**
     * @var string
     */
    const PROVISION = 'CreateAccount';

    /**
     * @var string
     */
    const TERMINATE = 'TerminateAccount';

    /**
     * @var string
     */
    const SUSPEND = 'SuspendAccount';

    /**
     * @var string
     */
    const UNSUSPEND = 'UnsuspendAccount';

    /**
     * @var string
     */
    const USAGE = 'UsageUpdate';

    /**
     * @var LogFactory`
     */
    protected $log;

    /**
     * @var UsageUpdater
     */
    protected $usage;

    /**
     * @var ServerService
     */
    protected $server;

    /**
     * @var ServerProvisioner
     */
    protected $provision;

    /**
     * @var WhmcsConfig
     */
    protected $config;

    /**
     * @var TicketManager
     */
    protected $ticket;

    /**
     * @param LogFactory        $log
     * @param WhmcsConfig       $config
     * @param UsageUpdater      $usage
     * @param ServerService     $server
     * @param TicketManager     $ticket
     * @param ServerProvisioner $provision
     */
    public function __construct(
        LogFactory $log,
        WhmcsConfig $config,
        UsageUpdater $usage,
        ServerService $server,
        TicketManager $ticket,
        ServerProvisioner $provision
    ) {
        $this->log = $log;
        $this->usage = $usage;
        $this->config = $config;
        $this->server = $server;
        $this->ticket = $ticket;
        $this->provision = $provision;
    }

    /**
     * Triggered on Server Provisioning.
     *
     * @return string
     */
    public function provision()
    {
        try {
            $server = $this->provision->create();

            if (!$server) {
                throw new \Exception(
                    'No Server found in inventory. '.
                    'Provisioning Ticket Created.'
                );
            }
        } catch (\Exception $exc) {
            return $exc->getMessage();
        }

        return 'success';
    }

    /**
     * Run the usage update function.
     *
     * @return string
     */
    public function usage()
    {
        return 'success';

        // TODO
        $billingId = $this->server->currentBillingId();

        return $this->usage->runAndLogErrors($billingId)
            ? 'success'
            : 'Error running usage update';
    }

    /**
     * Terminate an account, logging and returning any errors that occur.
     *
     * @return string|null
     */
    public function terminate()
    {
        try {
            $this->doDeleteAction();
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }
    }

    /**
     * Delete the current server using the action chosen in settings.
     */
    protected function doDeleteAction()
    {
        switch ($act = $this->config->option(WhmcsConfig::DELETE_ACTION)) {
        case WhmcsConfig::DELETE_ACTION_WIPE:
            $this->server->current()->wipe();

            return 'success';
        case WhmcsConfig::DELETE_ACTION_TICKET:
            $this->createCancellationTicket();

            return 'success';
        }

        throw new \RuntimeException(sprintf(
            'Unhandled delete action: %s',
            $act
        ));
    }

    /**
     * Run the create cancellation ticket delete action.
     */
    protected function createCancellationTicket()
    {
        $message = sprintf(
            'Server with billing ID %d has been terminated.',
            $this->server->currentBillingId()
        );

        $this->ticket->create([
            'clientid' => $this->config->get('userid'),
            'subject' => 'Server Cancellation',
            'message' => $message,
        ]);
    }

    /**
     * Triggered on a Suspension event.
     *
     * @return string
     */
    public function suspend()
    {
        try {
            $this->server->current()->suspend();

            return 'success';
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }
    }

    /**
     * Triggered on an Unsuspension event.
     */
    public function unsuspend()
    {
        try {
            $this->server->current()->unsuspend();

            return 'success';
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }
    }

    /**
     * @param \Exception $exc
     * @param string     $action
     */
    private function logException(\Exception $exc, $action)
    {
        $this->log->activity(
            'SynergyCP: error during %s: %s',
            $action,
            $exc->getMessage()
        );
    }

    /**
     * @return array
     */
    public static function functions()
    {
        return [
            static::PROVISION => 'provision',
            static::USAGE => 'usage',
            static::TERMINATE => 'terminate',
            static::SUSPEND => 'suspend',
            static::UNSUSPEND => 'unsuspend',
        ];
    }
}
