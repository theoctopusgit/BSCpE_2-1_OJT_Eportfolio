<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeploymentService
{
    public function getForUser(User $user): ?Deployment
    {
        return Deployment::where('user_id', $user->id)
            ->with('company')
            ->orderByRaw("status = 'confirmed' desc")
            ->latest('id')
            ->first();
    }

    public function confirm(Deployment $deployment, User $user, array $overrides): Deployment
    {
        if ($deployment->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'deployment' => 'You may only confirm your own deployment.',
            ]);
        }

        if ($deployment->status === 'confirmed') {
            throw ValidationException::withMessages([
                'deployment' => 'This deployment is already confirmed.',
            ]);
        }

        $deployment->fill(array_filter($overrides, fn ($v) => $v !== null));
        $deployment->status = 'confirmed';
        $deployment->confirmed_at = now();
        $deployment->confirmed_by = $user->id;
        $deployment->save();

        return $deployment->fresh('company');
    }

    public function update(Deployment $deployment, User $user, array $data): Deployment
    {
        if ($deployment->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'deployment' => 'You may only update your own deployment.',
            ]);
        }

        if ($deployment->status !== 'confirmed') {
            throw ValidationException::withMessages([
                'deployment' => 'Pending deployments must be confirmed first.',
            ]);
        }

        $deployment->fill(array_filter($data, fn ($v) => $v !== null));
        $deployment->save();

        return $deployment->fresh('company');
    }

    /**
     * Export all confirmed deployments to a CSV string.
     */
    public function exportConfirmedCsv(): string
    {
        $deployments = Deployment::with(['user', 'company'])
            ->where('status', 'confirmed')
            ->orderBy('confirmed_at', 'desc')
            ->get();

        $output = fopen('php://temp', 'r+');

        // CSV Header (Explicit parameters passed to avoid PHP 8.4 fputcsv deprecation warning)
        fputcsv($output, [
            'Student ID',
            'Student Name',
            'Student Email',
            'Company Name',
            'Supervisor Name',
            'Supervisor Contact',
            'Start Date',
            'End Date',
            'Confirmed At',
        ], ',', '"', '\\');

        foreach ($deployments as $d) {
            $companyName = $d->company?->name ?? $d->company_name ?? 'N/A';

            fputcsv($output, [
                $d->user_id,
                $d->user?->name ?? 'N/A',
                $d->user?->email ?? 'N/A',
                $companyName,
                $d->supervisor_name ?? 'N/A',
                $d->supervisor_contact ?? 'N/A',
                $d->start_date ? $d->start_date->format('Y-m-d') : 'N/A',
                $d->end_date ? $d->end_date->format('Y-m-d') : 'N/A',
                $d->confirmed_at ? $d->confirmed_at->format('Y-m-d H:i:s') : 'N/A',
            ], ',', '"', '\\');
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }
}