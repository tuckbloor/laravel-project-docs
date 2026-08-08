<?php

namespace DevDocs\LaravelProjectDocs\Support;

class DescriptionGuesser
{
    public function forClass(string $name, ?string $extends = null): string
    {
        $short = $this->humanize($name);
        $extends = (string) $extends;

        return match (true) {
            str_ends_with($name, 'Controller') => "Handles HTTP request and response logic for {$this->humanize(substr($name, 0, -10))}.",
            str_contains($extends, 'Illuminate\\Database\\Eloquent\\Model') || $extends === 'Model' => "Represents {$short} data through an Eloquent model.",
            str_ends_with($name, 'Service') => "Contains application or domain service logic for {$this->humanize(substr($name, 0, -7))}.",
            str_ends_with($name, 'Command') => "Provides console or Artisan command behaviour for {$this->humanize(substr($name, 0, -7))}.",
            str_ends_with($name, 'Job') => "Defines queued or background work for {$this->humanize(substr($name, 0, -3))}.",
            str_ends_with($name, 'Mail') || str_ends_with($name, 'Mailable') => "Builds an email related to {$short}.",
            str_ends_with($name, 'Notification') => "Defines a notification related to {$this->humanize(substr($name, 0, -12))}.",
            str_ends_with($name, 'Policy') => "Defines authorization rules for {$this->humanize(substr($name, 0, -6))}.",
            str_ends_with($name, 'Middleware') => "Applies HTTP middleware behaviour for {$this->humanize(substr($name, 0, -10))}.",
            str_ends_with($name, 'Request') => "Defines request validation or authorization for {$this->humanize(substr($name, 0, -7))}.",
            default => "Defines the {$short} class.",
        };
    }

    public function forMethod(string $name): string
    {
        return match ($name) {
            'index' => 'Returns or prepares a listing of records or resources.',
            'show' => 'Returns or prepares one record or resource for display.',
            'create' => 'Prepares data required to create a new record or resource.',
            'store' => 'Validates and stores a new record or resource.',
            'edit' => 'Prepares an existing record or resource for editing.',
            'update' => 'Validates and updates an existing record or resource.',
            'destroy', 'delete' => 'Removes or deletes an existing record or resource.',
            '__construct' => 'Initialises the class and its dependencies.',
            default => 'Implements the '.strtolower($this->humanize($name)).' operation.',
        };
    }

    private function humanize(string $value): string
    {
        $value = preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace(['_', '-'], ' ', $value)) ?: $value;

        return trim($value);
    }
}
