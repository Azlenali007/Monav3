/**
 * Mona SMM Panel v2 - PHP Architecture & Gateway Deployment Dashboard
 * Note: The authoritative production application runs strictly in native PHP (index.php) and MySQL.
 */

import React, { useState } from 'react';
import { 
  ShieldCheck, 
  CreditCard, 
  Database, 
  Server, 
  CheckCircle2, 
  AlertTriangle, 
  Key, 
  Zap, 
  Lock,
  Globe,
  FileCode,
  Layers
} from 'lucide-react';

export default function App() {
  const [activeTab, setActiveTab] = useState<'gateways' | 'fixes' | 'architecture' | 'deployment'>('gateways');

  const gateways = [
    { name: 'Razorpay', region: 'India', code: 'razorpay', type: 'fiat', status: 'Implemented', auth: 'HMAC-SHA256 (X-Razorpay-Signature)', testMode: 'Supported' },
    { name: 'Cashfree Payments', region: 'India', code: 'cashfree', type: 'fiat', status: 'Implemented', auth: 'HMAC-SHA256 (Timestamp + Payload)', testMode: 'Supported' },
    { name: 'PhonePe Payment Gateway', region: 'India', code: 'phonepe', type: 'fiat', status: 'Implemented', auth: 'SHA256 + Salt Key (X-VERIFY Checksum)', testMode: 'Supported' },
    { name: 'PayU', region: 'India', code: 'payu', type: 'fiat', status: 'Implemented', auth: 'SHA512 Reverse Hash Verification', testMode: 'Supported' },
    { name: 'PayPal Checkout', region: 'International', code: 'paypal', type: 'fiat', status: 'Implemented', auth: 'OAuth2 + Server-Side Capture Handshake', testMode: 'Supported' },
    { name: 'Stripe Checkout', region: 'International', code: 'stripe', type: 'fiat', status: 'Implemented', auth: 'Native HMAC-SHA256 (stripe-signature)', testMode: 'Supported' },
    { name: 'Verifone / 2Checkout', region: 'International', code: 'twocheckout', type: 'fiat', status: 'Implemented', auth: 'INS HMAC-MD5 Hash Algorithm', testMode: 'Supported' },
    { name: 'Cryptomus', region: 'Cryptocurrency', code: 'cryptomus', type: 'crypto', status: 'Implemented', auth: 'MD5-Base64 Payload Signature', testMode: 'Supported' },
    { name: 'NOWPayments', region: 'Cryptocurrency', code: 'nowpayments', type: 'crypto', status: 'Implemented', auth: 'HMAC-SHA512 Sorted JSON Hash', testMode: 'Supported' },
    { name: 'CoinPayments', region: 'Cryptocurrency', code: 'coinpayments', type: 'crypto', status: 'Implemented', auth: 'IPN HMAC-SHA512 Header Verification', testMode: 'Supported' },
    { name: 'Binance Pay', region: 'Cryptocurrency', code: 'binancepay', type: 'crypto', status: 'Implemented', auth: 'v3 Merchant API HMAC-SHA512 with Nonce', testMode: 'Supported' },
    { name: 'Manual Bank / UPI Transfer', region: 'Local / Offline', code: 'manual', type: 'manual', status: 'Implemented', auth: 'Admin Verification & Approval Required', testMode: 'Supported' }
  ];

  const fixes = [
    {
      title: 'Payment Idempotency & Duplicate Credit Guard',
      location: 'api/payments.php & GatewayManager.php',
      details: 'Implemented row-level locking (SELECT ... FOR UPDATE) and atomic transactions. Payment status is checked before crediting; duplicate webhooks return safe 200 responses without double crediting.'
    },
    {
      title: 'Session Fixation Security Fix',
      location: 'login.php',
      details: 'Added session_regenerate_id(true) immediately upon successful password verification, preventing session fixation vulnerability.'
    },
    {
      title: 'Double Refund Elimination in Cron',
      location: 'cron/orders.php',
      details: 'Added atomic transaction locks and refunded_amount tracking. Overlapping cron processes can never credit multiple cancellations for the same order.'
    },
    {
      title: 'Drip-Feed Column Standardization',
      location: 'user/drip-feed.php & cron/dripfeed.php',
      details: 'Aligned queries and schema to runs_completed, eliminating fatal PDOException "Unknown column total_runs_completed".'
    },
    {
      title: 'Safe Database Migration 004',
      location: 'database/migrations/004_payment_gateways_and_fixes.sql',
      details: 'Added non-destructive migration ensuring payment_gateways, payment_webhook_events, and idempotency fields are created without dropping existing tables.'
    },
    {
      title: 'Sensitive Files Protection (.htaccess & Nginx)',
      location: '.htaccess & nginx.conf.example',
      details: 'Explicitly denied web access to .sql, .lock, .env, .json, and /includes/ /database/ directories, with index.php given absolute precedence over index.html.'
    },
    {
      title: 'Credential Encryption with OpenSSL AES-256-GCM',
      location: 'includes/functions.php & admin/gateways.php',
      details: 'Secret keys and salt phrases are stored in MySQL with authenticated AES-256-GCM encryption. Passwords are never printed or dumped in plaintext.'
    },
    {
      title: 'Server-Side Price Recomputation',
      location: 'user/new-order.php & user/mass-order.php',
      details: 'Client-submitted charges are strictly ignored; prices are recalculated on the server based on authoritative service rates before atomic balance debit.'
    }
  ];

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
      {/* Top Banner */}
      <header className="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center font-black text-white shadow-lg shadow-blue-500/25">
              M
            </div>
            <div>
              <span className="font-bold text-lg text-white">Mona SMM Panel v2</span>
              <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                PHP 8.2+ Architecture
              </span>
            </div>
          </div>
          <div className="flex items-center space-x-2">
            <span className="text-xs text-slate-400 hidden sm:inline">Production Entry Point:</span>
            <code className="text-xs font-mono bg-slate-800 text-blue-400 px-2 py-1 rounded">/index.php</code>
          </div>
        </div>
      </header>

      {/* Main Container */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full space-y-8">
        
        {/* Navigation Tabs */}
        <div className="flex items-center space-x-2 border-b border-slate-800 pb-3">
          <button 
            onClick={() => setActiveTab('gateways')}
            className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold transition ${activeTab === 'gateways' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'text-slate-400 hover:text-white hover:bg-slate-900'}`}
          >
            <CreditCard className="w-4 h-4" />
            <span>12 Payment Gateways</span>
          </button>
          <button 
            onClick={() => setActiveTab('fixes')}
            className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold transition ${activeTab === 'fixes' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'text-slate-400 hover:text-white hover:bg-slate-900'}`}
          >
            <ShieldCheck className="w-4 h-4" />
            <span>Verified Bug Fixes</span>
          </button>
          <button 
            onClick={() => setActiveTab('architecture')}
            className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold transition ${activeTab === 'architecture' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'text-slate-400 hover:text-white hover:bg-slate-900'}`}
          >
            <Layers className="w-4 h-4" />
            <span>PHP Architecture</span>
          </button>
          <button 
            onClick={() => setActiveTab('deployment')}
            className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold transition ${activeTab === 'deployment' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'text-slate-400 hover:text-white hover:bg-slate-900'}`}
          >
            <Server className="w-4 h-4" />
            <span>Deployment Guide</span>
          </button>
        </div>

        {/* Tab 1: Payment Gateways */}
        {activeTab === 'gateways' && (
          <div className="space-y-6">
            <div className="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
              <h2 className="text-xl font-bold text-white mb-1">Provider-Adapter Gateway Architecture</h2>
              <p className="text-slate-400 text-sm">
                Each gateway implements <code className="text-blue-400 font-mono">GatewayInterface</code> in <code className="text-slate-300 font-mono">includes/gateways/</code> using official HTTP APIs and PHP cURL (zero external Composer SDKs required).
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {gateways.map((g) => (
                <div key={g.code} className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 hover:border-slate-700 transition">
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-white text-base">{g.name}</span>
                    <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${g.type === 'crypto' ? 'bg-purple-500/20 text-purple-300' : (g.type === 'manual' ? 'bg-amber-500/20 text-amber-300' : 'bg-blue-500/20 text-blue-300')}`}>
                      {g.region}
                    </span>
                  </div>
                  <div className="text-xs space-y-1.5 text-slate-400 pt-2 border-t border-slate-800">
                    <div className="flex justify-between">
                      <span>Adapter:</span>
                      <code className="text-blue-400 font-mono">{g.code}</code>
                    </div>
                    <div className="flex justify-between">
                      <span>Verification:</span>
                      <span className="text-slate-300 font-mono text-[11px] truncate max-w-[180px]">{g.auth}</span>
                    </div>
                    <div className="flex justify-between">
                      <span>Sandbox Mode:</span>
                      <span className="text-emerald-400 font-medium">{g.testMode}</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Tab 2: Bug Fixes */}
        {activeTab === 'fixes' && (
          <div className="space-y-4">
            <div className="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 mb-4">
              <h2 className="text-xl font-bold text-white mb-1">Critical Defect Resolutions</h2>
              <p className="text-slate-400 text-sm">All 9 verified issues identified during Stage 1 and Stage 2 code audits have been safely resolved.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {fixes.map((f, i) => (
                <div key={i} className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-2">
                  <div className="flex items-center space-x-2">
                    <CheckCircle2 className="w-5 h-5 text-emerald-400 flex-shrink-0" />
                    <h3 className="font-bold text-white text-sm">{f.title}</h3>
                  </div>
                  <div className="text-xs text-blue-400 font-mono">{f.location}</div>
                  <p className="text-slate-400 text-xs leading-relaxed">{f.details}</p>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Tab 3: PHP Architecture */}
        {activeTab === 'architecture' && (
          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
            <h2 className="text-xl font-bold text-white">Application Architecture Breakdown</h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
              <div className="bg-slate-950 p-5 rounded-xl border border-slate-800 space-y-2">
                <div className="flex items-center space-x-2 text-blue-400 font-bold">
                  <Server className="w-4 h-4" />
                  <span>PHP Backend Core</span>
                </div>
                <p className="text-slate-400 text-xs">
                  Native PHP 8.2+ with strict typing where practical. PDO MySQL with prepared statements, emulated prepares disabled, and UTF-8mb4 collation.
                </p>
              </div>

              <div className="bg-slate-950 p-5 rounded-xl border border-slate-800 space-y-2">
                <div className="flex items-center space-x-2 text-emerald-400 font-bold">
                  <Database className="w-4 h-4" />
                  <span>MySQL 8+ / MariaDB</span>
                </div>
                <p className="text-slate-400 text-xs">
                  Unified baseline schema in <code className="text-slate-300">database/schema.sql</code> plus non-destructive migration <code className="text-slate-300">004_payment_gateways_and_fixes.sql</code>. Zero SQLite dependencies.
                </p>
              </div>

              <div className="bg-slate-950 p-5 rounded-xl border border-slate-800 space-y-2">
                <div className="flex items-center space-x-2 text-purple-400 font-bold">
                  <Zap className="w-4 h-4" />
                  <span>Tailwind & Alpine.js</span>
                </div>
                <p className="text-slate-400 text-xs">
                  Frontend styling uses Tailwind CSS. Reactive UI dropdowns and calculators powered by Alpine.js. SweetAlert2 handles modals; Chart.js is restricted to admin.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Tab 4: Deployment Guide */}
        {activeTab === 'deployment' && (
          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
            <h2 className="text-xl font-bold text-white">Web Server Deployment Guidelines</h2>
            <div className="space-y-4 text-xs text-slate-300">
              <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                <span className="font-bold text-white text-sm block">1. Apache Web Server</span>
                <p>The root <code className="text-blue-400 font-mono">.htaccess</code> is configured with <code className="text-slate-200">DirectoryIndex index.php index.html</code>, ensuring Apache routes to <code className="text-slate-200">index.php</code> and denies direct HTTP access to <code className="text-slate-200">.sql</code>, <code className="text-slate-200">.lock</code>, <code className="text-slate-200">.env</code>, and sensitive directories.</p>
              </div>

              <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                <span className="font-bold text-white text-sm block">2. Nginx Server Configuration</span>
                <p>Reference configuration provided in <code className="text-blue-400 font-mono">nginx.conf.example</code>. Sets <code className="text-slate-200">index index.php;</code> and blocks locations matching <code className="text-slate-200">^/(database|includes|cron)/</code>.</p>
              </div>

              <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                <span className="font-bold text-white text-sm block">3. Background Crontab Automation</span>
                <pre className="bg-slate-900 p-3 rounded text-slate-300 font-mono overflow-x-auto">
{`# Run order status sync and auto-refunds every 2 minutes
*/2 * * * * php /var/www/html/cron/orders.php > /dev/null 2>&1

# Run drip-feed interval scheduler every 5 minutes
*/5 * * * * php /var/www/html/cron/dripfeed.php > /dev/null 2>&1

# Run provider service rates sync daily
0 2 * * * php /var/www/html/cron/services.php > /dev/null 2>&1`}
                </pre>
              </div>
            </div>
          </div>
        )}

      </main>
    </div>
  );
}
