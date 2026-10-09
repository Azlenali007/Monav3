<?php
/**
 * Mona SMM Panel v2 - Manual Bank / UPI Transfer Adapter
 * Creates pending deposit requests requiring administrative approval
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class ManualGateway extends AbstractGateway {
    public function getCode(): string {
        return 'manual';
    }

    public function getName(): string {
        return 'Manual Bank / UPI Transfer';
    }

    public function getType(): string {
        return 'manual';
    }

    public function getConfigFields(): array {
        return [
            'instructions' => [
                'label' => 'Payment Instructions / Bank / UPI Details',
                'type' => 'textarea',
                'required' => true,
                'default' => "Bank: Example Bank\nAccount: 1234567890\nIFSC: EXAMP000123\nUPI ID: payments@upi\nSend payment and enter UTR/Reference number below."
            ]
        ];
    }

    public function isConfigured(): bool {
        return true; // Always usable if enabled
    }

    public function createPayment(array $payment, array $user): array {
        $instructions = $this->getCredential('instructions') ?: ($this->config['instructions'] ?? 'Please contact admin for deposit details.');
        $utr = cleanInput($_POST['manual_reference'] ?? '');

        return [
            'status' => 'custom',
            'gateway_ref' => $utr ?: $payment['transaction_id'],
            'instructions' => $instructions,
            'custom_data' => [
                'instructions' => $instructions,
                'requires_reference' => true
            ]
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        // Manual payments never automatically verify via external webhook
        return [
            'verified' => false,
            'error' => 'Manual transfers require administrative approval'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        return ['status' => 'pending'];
    }
}
