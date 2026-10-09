<?php
/**
 * Mona SMM Panel v2 - Payment Gateway Interface
 */

declare(strict_types=1);

namespace Mona\Gateways;

interface GatewayInterface {
    /**
     * Unique alphanumeric identifier (e.g., 'razorpay', 'stripe')
     */
    public function getCode(): string;

    /**
     * Human-readable display title
     */
    public function getName(): string;

    /**
     * Category type: 'fiat', 'crypto', or 'manual'
     */
    public function getType(): string;

    /**
     * List of configuration field definitions for admin UI
     */
    public function getConfigFields(): array;

    /**
     * Check if all required credentials are fully configured
     */
    public function isConfigured(): bool;

    /**
     * Initiate checkout with provider (returns redirect URL or frontend params)
     * 
     * @param array $payment The pending payment record from database
     * @param array $user The authenticated user placing the deposit
     * @return array [
     *     'status' => 'redirect'|'custom'|'error',
     *     'redirect_url' => ?string,
     *     'gateway_ref' => ?string,
     *     'custom_data' => ?array,
     *     'instructions' => ?string,
     *     'error' => ?string
     * ]
     */
    public function createPayment(array $payment, array $user): array;

    /**
     * Verify authenticity of incoming webhook or server callback
     * 
     * @param array $headers Incoming HTTP request headers
     * @param string $rawPayload Raw php://input request body
     * @param array $postData Decoded or $_POST parameters
     * @return array [
     *     'verified' => bool,
     *     'event_id' => ?string,
     *     'transaction_id' => ?string,
     *     'gateway_ref' => ?string,
     *     'amount' => ?float,
     *     'currency' => ?string,
     *     'status' => 'completed'|'failed'|'pending',
     *     'error' => ?string
     * ]
     */
    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array;

    /**
     * Query authoritative payment status directly from provider API
     */
    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array;
}
