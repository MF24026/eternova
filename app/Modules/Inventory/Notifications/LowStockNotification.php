<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Inventory\Models\BranchInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BranchInventory $inventory,
        private readonly int $threshold,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $variant = $this->inventory->productVariant;
        $branch = $this->inventory->branch;
        $productName = $variant?->product?->name ?? 'Unknown product';
        $sku = $variant?->sku ?? 'N/A';
        $branchName = $branch?->name ?? 'Unknown branch';
        $available = $this->inventory->available;

        return (new MailMessage)
            ->subject("Low stock alert: {$sku} at {$branchName}")
            ->line('A product variant has fallen below its stock alert threshold.')
            ->line("**Product:** {$productName}")
            ->line("**SKU:** {$sku}")
            ->line("**Branch:** {$branchName}")
            ->line("**Available units:** {$available}")
            ->line("**Alert threshold:** {$this->threshold}")
            ->line('Please restock this variant to avoid stockouts.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $variant = $this->inventory->productVariant;
        $branch = $this->inventory->branch;

        return [
            'type' => 'inventory.low_stock',
            'branch_inventory_id' => $this->inventory->id,
            'product_variant_id' => $this->inventory->product_variant_id,
            'sku' => $variant?->sku,
            'product_name' => $variant?->product?->name,
            'branch_name' => $branch?->name,
            'available' => $this->inventory->available,
            'threshold' => $this->threshold,
        ];
    }

    public function databaseType(mixed $notifiable): string
    {
        return 'inventory.low_stock';
    }
}
