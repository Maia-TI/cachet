<?php

namespace App\Contracts;

interface MonitorInterface
{
    /**
     * Get the URL to monitor.
     */
    public function getUrl(): string;

    /**
     * Get the component ID associated with this monitor.
     */
    public function getComponentId(): int;

    /**
     * Get the name used for the incident.
     */
    public function getMonitorName(): string;

    /**
     * Get the public name for display messages.
     */
    public function getPublicName(): string;
}
