# Payload Encryption Analysis

Investigation into encrypting sensitive fields (amount, currency) in transit and the
feasibility of end-to-end encryption between established contacts.

**Status**: Phase 1 implemented. Phase 2 deferred (see findings below).

---

## 1. Current State

### What's Protected Today

| Layer | Mechanism | Protects Against |
|-------|-----------|------------------|
| Transport | HTTPS/TLS | Passive network eavesdropping |
| Transport | Tor hidden services | IP-level traffic analysis |
| Integrity | EC signature (secp256k1) on every message | Tampering, impersonation |
| At-rest | AES-256-GCM | Private key theft from disk |
| Routing privacy | Per-hop sender rewriting | End-to-end identity correlation |

### What's Exposed

Relay nodes in a P2P chain currently see these fields **in cleartext**:

| Field | Why it's visible | Who sees it |
|-------|------------------|-------------|
| `amount` | Included in signed P2P payload | Every relay node |
| `currency` | Included in signed P2P payload | Every relay node |
| `hash` | P2P routing identifier | Every relay node |
| `requestLevel` | Hop counter | Every relay node |
| `senderAddress` | Immediate upstream node | Next hop only |

Fields already hidden from relays: `description` (excluded from signature, not forwarded),
`endRecipientAddress` and `initialSenderAddress` (local tracking only, never transmitted).

### Current Crypto Primitives

- **Keys**: EC secp256k1 (or prime256v1 fallback), derived deterministically from BIP39 seed
- **Signing**: `openssl_sign()` / `openssl_verify()` with EC private/public keys
- **Symmetric encryption**: AES-256-GCM via `KeyEncryption` class (currently only for at-rest storage)
- **No asymmetric encryption**: `openssl_public_encrypt()` is not used anywhere in the codebase
- **Key exchange**: Public keys exchanged during contact establishment (both parties store each other's EC public key)

---

## 2. Threat Model

### What We're Protecting Against

1. **Compromised relay node**: A node in the P2P chain is malicious or hacked. It can see
   all cleartext fields passing through it (amount, currency, hash).

2. **Traffic analysis at relay**: Even without knowing identities, a relay can correlate
   amounts across P2P requests to infer payment patterns.

3. **Network-level observer**: Someone monitoring traffic between nodes (mitigated by TLS/Tor,
   but defense-in-depth is valuable).

### What We're NOT Protecting Against (Out of Scope)

- Compromised sender or recipient endpoint (game over regardless)
- Side-channel timing analysis (separate concern)
- Quantum computing threats (separate migration path)

---

## 3. The Relay Node Dilemma

### Why This Is Hard for P2P

In the current P2P routing model, relay nodes **need** the `amount` to function:

1. **Fee calculation**: Each relay calculates its fee based on the transaction amount
   (`calculateFee(amount, feePercent, minFee, currency)`)
2. **Capacity check**: Relay verifies it has sufficient balance/credit to handle the amount
3. **Currency validation**: Relay must know the currency to check if it supports it
4. **Amount forwarding**: The same amount is forwarded to the next hop

This means **full E2E encryption of amount/currency in P2P is incompatible with the
current relay fee model**. A relay that can't read the amount can't calculate its fee
or verify capacity.

### Direct Transactions Are Different

For direct transactions between established contacts (no relay intermediaries):
- Sender knows the recipient's public key
- No relay nodes need to read the payload
- Full E2E encryption is straightforward

---

## 4. Options

### Option A: E2E Encryption for Direct Transactions

**Scope**: All direct (non-P2P) transactions between established contacts.

**Mechanism**: ECDH + AES-256-GCM hybrid encryption

```
Sender has: own EC private key + recipient's EC public key (from contact exchange)
1. Derive shared secret: ECDH(sender_private, recipient_public)
2. Derive symmetric key: HKDF-SHA256(shared_secret, context="eiou-e2e")
3. Encrypt payload fields: AES-256-GCM(symmetric_key, {amount, currency, memo, ...})
4. Send encrypted envelope + ephemeral public key
5. Recipient derives same shared secret and decrypts
```

**What gets encrypted**: `amount`, `currency`, `txid`, `previousTxid`, `memo`

**What stays cleartext**: `type` (for message routing), `senderAddress`, `senderPublicKey`,
`receiverAddress`, `signature`

**Pros**:
- Only sender and recipient can read financial data
- Uses existing EC key infrastructure (no new key types)
- `KeyEncryption` class already implements AES-256-GCM (can be extended)
- ECDH shared secret is the standard approach for EC-based encryption

**Cons**:
- Only works after contact establishment (public keys must be exchanged first)
- Adds ~50-100 bytes overhead per message (IV + auth tag + ephemeral key)
- Recipient must attempt decryption to determine if message is valid
- Need to handle key rotation / re-derivation if contact keys change

**Complexity**: Medium. The crypto primitives already exist in the codebase. Main work is:
- Add ECDH shared secret derivation
- Extend `KeyEncryption` for payload-level encryption (currently only does at-rest)
- Update `TransactionPayload::build()` to encrypt sensitive fields
- Update `TransactionService` receive path to decrypt before processing

### Option B: Relay-Encrypted P2P (Hop-by-Hop)

**Scope**: P2P chain transactions — encrypt payload between each hop.

**Mechanism**: Each sender encrypts for the next hop using the next node's public key.

```
Originator → Relay A → Relay B → End-recipient

Step 1: Originator encrypts P2P payload with Relay A's public key
Step 2: Relay A decrypts, calculates fee, re-encrypts with Relay B's public key
Step 3: Relay B decrypts, calculates fee, re-encrypts with End-recipient's public key
Step 4: End-recipient decrypts
```

**What it protects**: Network observers between hops can't read payload (already handled
by TLS, so marginal benefit).

**What it does NOT protect**: Each relay still sees amount/currency in cleartext after
decrypting. A compromised relay still sees everything.

**Pros**:
- Provides defense-in-depth on top of TLS
- Compatible with current fee model (relays can still calculate fees)

**Cons**:
- Does NOT solve the compromised-relay threat (the main goal)
- Significant complexity: encrypt/decrypt at every hop
- Performance overhead on every relay
- Adds failure modes (key mismatch, decryption errors at each hop)
- Marginal security benefit over TLS

**Complexity**: High. **Not recommended** — the security gain doesn't justify the cost.

### Option C: Blinded Amount P2P (Advanced, Future)

**Scope**: Hide exact amounts from relay nodes while still allowing fee calculation.

**Mechanism**: Cryptographic range proofs or blinded amounts (inspired by Confidential
Transactions in Bitcoin/Monero).

```
Instead of amount=1000:
1. Sender creates a Pedersen commitment: C = amount*G + blinding*H
2. Sender provides a range proof: proves amount is in [0, MAX] without revealing it
3. Relay calculates fee on the commitment (homomorphic property)
4. Only recipient can unblind to see the actual amount
```

**Pros**:
- True privacy: relays process amounts they can't read
- Mathematically sound (proven in cryptocurrency literature)

**Cons**:
- Very high complexity (requires elliptic curve math beyond what OpenSSL provides)
- Would need a library like libsecp256k1-zkp or similar
- Range proofs add significant payload size (~2-5 KB per proof)
- Fee calculation on blinded amounts requires homomorphic commitment scheme changes
- Major architectural change to the entire payment flow
- PHP ecosystem has limited support for these primitives

**Complexity**: Very high. **Not recommended for near-term**. Worth revisiting if the
project moves toward a cryptocurrency-grade privacy model.

### Option D: Hybrid — E2E for Direct + Reduced Exposure for P2P

**Scope**: Combine Option A for direct transactions with targeted improvements for P2P.

**Direct transactions**: Full E2E encryption (Option A).

**P2P transactions**: Instead of encrypting amounts, reduce what relays see:
1. **Remove currency from P2P payload** — relay nodes only need to know they support
   the currency, which they already validated when accepting the contact. Store currency
   in the contact relationship, not in each P2P payload.
2. **Hash the amount for correlation resistance** — relay logs amount hashes instead of
   plaintext amounts, making post-hoc analysis harder.
3. **Encrypt the RP2P return payload** — the return leg can be E2E encrypted between
   end-recipient and originator (they'll be contacts by the time RP2P flows).

**Pros**:
- Pragmatic: addresses the highest-value scenario (direct transactions) immediately
- Incrementally improves P2P privacy without breaking the fee model
- Can be implemented in phases

**Cons**:
- P2P relays still see amount (necessary for fee calculation)
- Currency removal from payload requires contact-level currency tracking

**Complexity**: Medium (Phase 1: E2E direct) + Low (Phase 2: P2P tweaks).

---

## 5. Implementation — Phase 1 (Completed)

**Option D (Hybrid)** selected. Phase 1 implemented, Phase 2 deferred.

### Design Decisions Made

1. **Ephemeral ECDH**: Fresh ephemeral key per message for forward secrecy. Compromising
   a long-term key does not expose past messages.

2. **Encrypt-then-sign**: Sensitive fields are encrypted first, then the ciphertext is
   signed. Signature verification works without decryption (important for `index.html`
   to verify before routing).

3. **Selective field encryption**: Only `amount`, `currency`, `txid`, `previousTxid`,
   `memo` are encrypted. Routing fields (`type`, `time`, `receiverAddress`,
   `receiverPublicKey`, `senderAddress`, `senderPublicKey`) stay cleartext.

4. **No backward compatibility**: Fresh install only — all nodes support encryption.

### Encryption Flow

```
SENDER (TransportUtilityService::signWithCapture):
1. Build payload with plaintext fields (stored in sender's DB)
2. Strip transport metadata (senderAddress, senderPublicKey, description)
3. Detect direct transaction (type=send, memo=standard, receiverPublicKey present)
4. Extract sensitive fields, encrypt with PayloadEncryption::encryptForRecipient()
   - Generate ephemeral EC key (same curve as recipient)
   - ECDH(ephemeral_private, recipient_public) -> shared secret
   - HKDF-SHA256(shared_secret, "eiou-payload-e2e") -> symmetric key
   - AES-256-GCM encrypt -> {ciphertext, iv, tag, ephemeralKey}
5. Replace plaintext fields with 'encrypted' block in message content
6. Add nonce, JSON encode, sign with sender's EC private key
7. Build envelope: {senderAddress, senderPublicKey, message, signature}

RECIPIENT (index.html -> TransactionProcessingService):
1. Verify signature against message (contains encrypted block) — passes
2. Detect 'encrypted' field in decoded message content
3. PayloadEncryption::decryptFromSender(encrypted, recipientPrivateKey)
   - ECDH(recipient_private, ephemeral_public) -> same shared secret
   - Same HKDF -> same symmetric key
   - AES-256-GCM decrypt -> {amount, currency, txid, previousTxid, memo}
4. Merge decrypted fields back, remove 'encrypted' block
5. Store envelope['message'] as signedMessageContent for sync verification
6. All downstream code (validation, processing, storage) receives plaintext
```

### Files Changed

| File | Change |
|------|--------|
| `files/src/security/PayloadEncryption.php` | **New** — ECDH + AES-256-GCM hybrid encryption |
| `files/src/services/utilities/TransportUtilityService.php` | Encrypt sensitive fields in `signWithCapture()` for direct transactions |
| `files/root/www/eiou/index.html` | Decrypt incoming encrypted payloads before routing |
| `files/src/database/DatabaseSchema.php` | Add `signed_message_content` column to transactions table |
| `files/src/database/TransactionRepository.php` | Store `signedMessageContent` in `insertTransaction()` |
| `files/src/services/SyncService.php` | Use stored signed message for signature verification |
| `files/src/schemas/payloads/TransactionPayload.php` | Handle encrypted content in `generateRecipientSignature()` |
| `tests/Unit/Security/PayloadEncryptionTest.php` | **New** — 17 tests covering round-trip, tampering, cross-node, sign workflow |

### What Is NOT Encrypted

- **P2P relay transactions**: Relay nodes need cleartext `amount` and `currency` for
  fee calculation and capacity checks. P2P payloads (`type=p2p`, `type=rp2p`) are
  not encrypted.
- **Contact requests**: `type=create` payloads have no sensitive financial data.
- **Status messages**: `type=message` payloads are informational.

---

## 6. Phase 2 Findings (Deferred)

Investigation revealed that the originally proposed Phase 2 items are either not
feasible or premature:

### RP2P E2E Encryption — Not Feasible

The end-recipient in a P2P chain does **not** know the originator's public key. The
P2P chain deliberately hides endpoint identities — each hop rewrites `senderAddress`
and `senderPublicKey` to its own. The end-recipient only knows the immediate upstream
relay's identity. Without the originator's public key, the end-recipient cannot encrypt
an E2E payload for the originator.

**Enabling this would require**: Including the originator's public key (encrypted or
hashed) in the P2P payload so the end-recipient can derive a shared secret. This is a
significant protocol change that needs separate design work.

### Currency Removal from P2P — Premature

Relay nodes currently need `currency` in the P2P payload to:
- Validate they support the requested currency
- Calculate fees in the correct currency
- Forward with the correct currency context

The `contact_currencies` table only stores the currency for a specific contact pair,
not for arbitrary P2P requests from unknown senders. Removing currency from P2P
payloads requires redesigning how multi-currency P2P routing works.

### Remaining P2P Privacy — Future Work

For reducing P2P data exposure, future options include:
- **Amount bucketing**: Round amounts to predefined buckets to reduce correlation
- **Blinded amounts** (Option C): Pedersen commitments with range proofs
- **Originator key inclusion**: Encrypted originator public key in P2P payload to
  enable RP2P E2E encryption

These are significant architectural changes best addressed in dedicated design efforts.

---

## 7. References

| File | Relevance |
|------|-----------|
| `files/src/security/PayloadEncryption.php` | ECDH + AES-256-GCM implementation |
| `files/src/security/KeyEncryption.php` | AES-256-GCM pattern (at-rest encryption) |
| `files/src/security/BIP39.php` | EC key derivation (secp256k1) |
| `files/src/services/utilities/TransportUtilityService.php` | Signing envelope + encryption trigger |
| `files/root/www/eiou/index.html` | Decryption on receive |
| `files/src/schemas/payloads/TransactionPayload.php` | Direct transaction payload structure |
| `files/src/schemas/payloads/P2pPayload.php` | P2P payload structure (not encrypted) |
| `files/src/schemas/payloads/Rp2pPayload.php` | Return P2P payload structure (not encrypted) |
| `files/src/schemas/payloads/ContactPayload.php` | Contact establishment / key exchange |
| `files/src/services/SyncService.php` | Signature verification with stored signed content |
