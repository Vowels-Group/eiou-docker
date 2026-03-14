# eIOU Docker Open Alpha: DEFINITIVE Reddit Launch Posts
# Copy/Paste Ready for Every Target Subreddit
# Compiled: March 13, 2026

## Key Status Update (Reflected in All Posts)

The Docker image is NOW PUBLIC (open alpha). This is the anchor for every post.

GitHub: github.com/eiou-org/eiou-docker
Website: eiou.org
Android app: Google Play (closed beta)
Stage: Open alpha (Docker), closed beta (Android)
Revenue: Pre-revenue

## Rules Applied to Every Post

1. No dashes (hyphens, en dashes, em dashes) anywhere
2. No asterisks anywhere
3. Founder affiliation disclosed in every post
4. At least one honest limitation included
5. No hype language ("revolutionary", "game-changing", "disrupting")
6. Each sub's self promo rules followed exactly

---

# POST 1: r/selfhosted

## Subreddit Intel

Members: ~553K
Self promo rules: "New Project Friday" (Rule 6) strictly enforced. Projects younger than 3 months must be posted on Fridays ONLY. Posts outside Friday are removed. Mods recently confirmed this rule is permanent (March 2026 update). Must be truly self-hostable with a self-hosted backend. No mobile-only apps.
Current mood: Skeptical of AI-generated code (Booklore controversy, ntfy backlash). Values transparency, honest devs, real architecture.
What gets upvoted: Docker compose files, honest "here's what works and what doesn't" posts, architecture decisions explained, projects that show real self-hosting value.
What gets removed: Marketing copy, closed-source products, vague "coming soon" posts, anything posted on the wrong day.

## Timing

Post on: FRIDAY (mandatory for new projects under 3 months old)
Time: 9 to 11 AM EST (peak traffic)
Use flair: "New Project" if available

## Title

eIOU: self-hosted P2P credit network node. Docker + Tor + trust-graph routing. Open alpha.

## Post Body

I just published the Docker image for eIOU, a peer-to-peer credit network where payments route through chains of personal trust instead of banks or blockchains.

Each node stores its own data. There is no central ledger. You set credit limits with people you trust (think: "I'd lend this person up to $200"). Payments find paths through the network using graph algorithms. All traffic routes through Tor by default.

The Docker setup:

```
docker pull ghcr.io/eiou-org/eiou-docker:latest
```

Or with compose:

```yaml
services:
  eiou-node:
    image: ghcr.io/eiou-org/eiou-docker:latest
    volumes:
      - ./data:/app/data
    ports:
      - "8080:8080"
    environment:
      - TOR_SOCKS_PORT=9050
      - API_PORT=8080
    restart: unless-stopped
```

What the node does:

Maintains trust lines (bilateral credit relationships) with other nodes
Routes payments through chains of trust using graph pathfinding
Runs "chain drops": scans for circular debts (A owes B owes C owes A) and cancels them automatically. In testing, 15 to 30% of total debt volume disappears this way
All network traffic goes through Tor

Honest limitations:

Tor adds 3 to 8 seconds of latency per operation. This is not for point of sale.
The network is very small (open alpha, tiny user base)
Pre-revenue. No clear monetization model yet.
No formal security audit
Documentation is sparse (working on it)
The Android app exists on Google Play (closed beta) but the Docker node is the primary self-hosted path

I would appreciate feedback on:

Does the compose setup look reasonable? Should Tor be a separate container or bundled?
What would you expect from a /health endpoint?
SQLite for single node state, or should I offer Postgres as an option?

Full disclosure: I am a founder of eIOU. This is open alpha, not production ready.

GitHub: github.com/eiou-org/eiou-docker
Site: eiou.org

## First Comment (post immediately after)

For anyone curious about the architecture: each node is a vertex in a directed weighted graph. Edges are trust lines with credit limits. When you initiate a payment, the system runs a modified max-flow algorithm to find the cheapest path through the trust graph. Each intermediate node can set a routing fee in basis points.

The "chain drops" feature does cycle detection via DFS, finds the minimum edge weight in each cycle, and subtracts it from all edges in the cycle. This is basically multilateral netting, the same thing CLS Bank does for $6+ trillion in daily forex settlement, but applied to individuals instead of banks.

Happy to go deep on any of this. The architecture was inspired by Ryan Fugger's original RipplePay (2004), the project that predated XRP.

## Top 5 Expected Questions and Responses

Q1: "Is this open source?"
A: "Yes, the Docker node is open source. Check the GitHub repo: github.com/eiou-org/eiou-docker. The Android app is currently closed source but the self-hosted node is where the open work lives."

Q2: "What's the threat model?"
A: "Tor routing prevents the server operator (me) from correlating senders and receivers. Each node only knows its direct trust connections. The threat model assumes an honest-but-curious operator. We do NOT claim protection against a global passive adversary. Full threat model discussion welcome."

Q3: "Why would I trust someone with credit?"
A: "You wouldn't trust a stranger. You'd set credit limits with people you already know and already lend to informally. The app just formalizes those existing relationships and enables routing through them."

Q4: "How is this different from Hawala?"
A: "It is digital hawala with automatic pathfinding and multilateral debt netting. The algorithm finds routes you would never calculate by hand and nets circular debts without human intervention."

Q5: "Can I run this on my Raspberry Pi?"
A: "The container is lightweight (Alpine-based). It should run on a Pi 4 or newer. Tor is the main resource consumer. If you try it, I would love to hear about the performance."

---

# POST 2: r/privacy

## Subreddit Intel

Members: ~1.8M
Self promo rules: EXTREMELY strict. Self-promotion is essentially banned. Product posts get removed immediately. Discussion about privacy concepts and architectures is fine. Only mention your project in comments if someone directly asks.
Current mood: Skeptical of everything. Demands verifiable claims. Loves threat model analysis.
What gets upvoted: Technical privacy architecture discussions, metadata analysis, threat model breakdowns, tool comparisons with specific criteria.
What gets removed: Product launches, vague privacy claims, beta invites, anything that smells like marketing.

## Timing

Post on: Tuesday to Thursday
Time: 9 to 11 AM EST
DO NOT mention eIOU in the post body. Only in comments if asked.

## Title

Payment metadata reveals more than transaction content. Why isn't graph structure privacy discussed more?

## Post Body

Most conversations about "private payments" focus on encrypting transaction amounts or obscuring blockchain addresses. I think the bigger exposure is structural: the graph of who pays whom, how often, and when.

Consider what a payment app operator can infer from metadata alone, even with encrypted amounts:

Timing patterns: Pay the same address every month on the 1st? That is likely rent. Your landlord is now known.
Frequency: Regular late-night payments to a specific recipient? Medical, personal, or otherwise sensitive.
Graph structure: The full map of who can pay whom reveals social relationships, dependencies, and hierarchies. This is arguably more sensitive than any individual transaction.
Correlation: Even without amounts, payment timing combined with social graph position can deanonymize users across platforms.

Payment apps that claim "encryption" typically encrypt content but retain the social graph. The operator knows your financial relationships even if they cannot read individual amounts.

One architectural approach: routing all traffic through Tor so the operator cannot correlate API requests to specific users. The operator sees payments arriving but cannot map sender to receiver. The tradeoff is latency (3 to 8 seconds per operation), which eliminates real-time payment use cases.

Another approach: making each payment node a Tor hidden service, removing the clearnet server entirely. This introduces compounding latency (each hop in a multi-hop payment adds a separate Tor circuit) but eliminates any central point of metadata collection.

Curious how people here rank these payment privacy vectors:

1. Transaction amounts (most systems address this)
2. Sender/receiver identities (most systems know this despite encryption)
3. Timing patterns (rarely discussed)
4. Network graph structure (almost never discussed)

For what threat model does each layer matter most?

## First Comment (do NOT post unless someone asks what you're building)

If asked: "Full disclosure: I work on a project called eIOU that implements Tor-routed payment paths through trust networks. Open alpha Docker image, closed beta Android app. Not trying to promote it here. The metadata question is genuinely what keeps me up at night because even with Tor, timing correlation is hard to fully prevent. Happy to discuss the architecture if anyone is interested."

## Top 5 Expected Questions and Responses

Q1: "What system are you talking about? Be specific."
A: "I work on eIOU (disclosure: founder). It routes all traffic through Tor and uses trust-graph routing. Open alpha on Docker, closed beta on Android. Happy to discuss the architecture but wanted to keep the post focused on the general privacy question."

Q2: "Just use Monero"
A: "Monero does excellent work on amount hiding and ring signatures. The layer I am focused on is different: even with Monero, a centralized exchange or wallet provider still sees your API request patterns and can build a social graph from timing metadata. Tor routing addresses that specific layer. Different part of the stack."

Q3: "Tor is compromised"
A: "Fair concern. Our threat model assumes a non-global adversary. Tor prevents the service operator from correlating users. If your threat model includes a global traffic analysis adversary, additional protections beyond Tor are needed. We are transparent about that boundary."

Q4: "If it's not open source, your claims are unverifiable"
A: "The Docker node is open source: github.com/eiou-org/eiou-docker. You can audit the Tor integration yourself."

Q5: "This is just a product pitch disguised as a discussion"
A: "I understand the skepticism. The metadata privacy question is genuine and applies well beyond my project. The ranking question (amounts vs. identities vs. timing vs. graph structure) is something I have not seen discussed much in this sub and I think it deserves attention regardless of any specific tool."

---

# POST 3: r/CryptoCurrency

## Subreddit Intel

Members: ~7.5M
Self promo rules: Very strict. All promotional posts need mod pre-approval. Undisclosed affiliations result in bans. Discussion framing is essential.
Current mood (March 2026): Active Ripple/XRP discussion (Australian AFSL win, mass adoption debates, SWIFT/Chainlink comparisons). DeFi "alive or dead" discourse. Skeptical of new projects but engaged on history and architecture.
What gets upvoted: Contrarian takes with substance, historical context, technical explainers, honest limitations, posts that invite debate.
What gets removed: Token shilling, undisclosed affiliations, low effort hype, duplicate promotional content.

## Timing

Post on: Tuesday to Thursday
Time: 6 to 8 PM UTC (catches both US and Asia audiences)
Consider: Ripple discussions are currently hot, making the original RipplePay angle especially timely.

## Title

Did crypto accidentally abandon the original Ripple idea? The trust-line model that predated XRP.

## Post Body

Ryan Fugger's RipplePay started in 2004 as a trust-based credit network.

Not a blockchain.
Not a token.
Not a validator game.

The basic idea: if Alice trusts Bob, and Bob trusts Carol, Alice can pay Carol through Bob. Each link has a credit limit and a small fee. Payments route through chains of trust using graph algorithms.

Then Jed McCaleb, Chris Larsen, and others pushed the project toward a consensus system, the XRP Ledger, and an enterprise path. That made it legible to markets and institutions. It also moved away from the original "trust network between people" model.

Two features from the original design space worth revisiting:

Trust line routing: payments follow paths of existing human relationships instead of going through validators or miners. No idle liquidity needed, just bilateral credit limits between people who know each other.

Multilateral debt netting: if A owes B, B owes C, and C owes A, the system detects the cycle and cancels the debts. No money moves. In testing with small groups, 15 to 30% of total debt volume nets to zero this way.

Genuine question for this sub:

Did crypto move toward global consensus and tokens because that was actually better for payments, or because it was easier to speculate on? I can see both sides. Trustless systems scale farther. Trust-based systems may map better to real human networks.

It feels like one of the more interesting roads crypto did not really finish exploring.

Full disclosure: I am building a project called eIOU that implements trust-graph routing with Tor privacy. Docker image just went public (open alpha), Android app in closed beta. Pre-revenue, tiny network. Not trying to shill. Genuinely curious if this sub thinks the trust-line model was abandoned for good reasons or just because it was not as financially attractive.

GitHub: github.com/eiou-org/eiou-docker

## First Comment (post immediately after)

Some context on how this differs from XRP's current trust lines on XRPL:

XRPL does support trust lines, but they operate within a global consensus ledger. Every transaction is an on-chain event validated by the network.

The original Fugger model (and what eIOU implements) has no global ledger at all. Each node maintains only its own bilateral relationships. There is no consensus mechanism because there is nothing to reach consensus about. Two people agree on a credit limit between themselves. That is the entire "protocol."

The tradeoff is obvious: no global state means no trustless verification. You are trusting people, not math. For institutional settlement, that is a nonstarter. For payments between people who already know each other, it might be a better fit.

The Tor routing is the other big difference. All eIOU traffic goes through onion routing so even the operator cannot correlate senders and receivers. Cost: 3 to 8 seconds per operation.

## Top 5 Expected Questions and Responses

Q1: "So you are making another XRP competitor?"
A: "Not competing with XRP at all. XRP is enterprise settlement infrastructure with a global consensus ledger. eIOU is peer-to-peer credit between people who know each other. No blockchain, no token, no validators. Totally different use case and architecture."

Q2: "This sounds like it could be a rug pull"
A: "Fair skepticism, this space has earned it. Key difference: there is no token to rug. No ICO. No fundraising from users. No investment opportunity. It is a credit network. You set credit limits with people you personally trust. The risk is credit risk with your friends, the same as lending someone $50 in real life."

Q3: "How do you make money?"
A: "Honestly, pre-revenue and still figuring that out. The app is free. Docker image is free and open source. Possible models include premium features or small network fees but nothing is decided. Transparency: this is a real business risk."

Q4: "Tor is too slow for payments"
A: "It is slow. 3 to 8 seconds. We are honest about that. This is not for buying coffee. It is for settling IOUs, paying freelancers, splitting group expenses. Transactions where 5 seconds does not matter but privacy does."

Q5: "Ryan Fugger sold the project. This has nothing to do with Ripple."
A: "Fugger did hand over the Ripple name and moved on. eIOU is not claiming to be Ripple. We are building on the trust-line routing concept he published, which is an idea, not a trademark. The ideas were always open."

---

# POST 4: r/fintech

## Subreddit Intel

Members: ~85K
Self promo rules: Must disclose affiliation. Need genuine participation history. 90% helpful content before mentioning your project. Transparent promotion accepted if substantive.
Current mood: Neobank feasibility discussions, B2B payments trends, regulatory focus (EU AI Act compliance).
What gets upvoted: Technical payment mechanics, real architecture breakdowns, "here's how X actually works" posts, regulatory analysis, honest founder posts.
What gets removed: Press releases, vague startup pitches, marketing copy.

## Timing

Post on: Monday to Wednesday
Time: 10 AM to 12 PM EST (US business hours)

## Title

How trust-graph routing works as a payment rail: architecture of a P2P credit network (with Docker node now in open alpha)

## Post Body

I have been building a payment system that routes through chains of personal trust instead of through banks, clearinghouses, or blockchains. The Docker image just went public so I wanted to share the architecture and get professional critique.

The model: Alice sets a $200 credit limit with Bob. Bob sets a $100 credit limit with Carol. When Alice wants to pay Carol, the system finds a path through the trust graph. Alice can pay Carol up to $100 through Bob. Bob earns a small routing fee (configurable in basis points). The algorithm is essentially a modified max-flow problem across a social network.

Two mechanisms I think are underexplored in fintech:

Trust-line routing: Every payment path consists of bilateral credit relationships. No idle liquidity pools, no reserve requirements, no correspondent banks. Capital efficiency is high because credit lines are only consumed when actively routing.

Multilateral netting ("chain drops"): The system periodically scans the trust graph for cycles. If A owes B, B owes C, and C owes A, it detects the cycle and subtracts the minimum amount from each edge. In testing with small groups, 15 to 30% of total debt volume nets to zero. Banks do this at scale (CLS Bank nets $6+ trillion daily) but consumer apps settle every transaction individually because volume is their revenue model.

Privacy: All traffic routes through Tor. The operator cannot correlate senders and receivers. Tradeoff: 3 to 8 seconds of latency.

Current state: Docker image is live (open alpha), Android app on Google Play (closed beta). Targeting Japan, Singapore, Thailand. Pre-revenue.

The obvious challenge: cold start. The network needs density to route payments effectively. A sparse graph means most payment paths do not exist yet.

I would genuinely appreciate professional critique. What are the failure modes you would worry about? Where does this model break at scale?

Full disclosure: I am a founder of eIOU.

GitHub: github.com/eiou-org/eiou-docker
Site: eiou.org

## First Comment (post immediately after)

For the payment infrastructure folks here: the settlement model is worth comparing to correspondent banking.

In traditional rails, a payment from Bank A's customer to Bank C's customer might go: Bank A -> Correspondent Bank -> Bank C. Each hop adds cost and settlement delay. The banks do not trust each other so they use pre-funded nostro/vostro accounts.

In trust-line routing, the "hops" are between people who have already agreed to extend credit to each other. No pre-funding needed. The credit limit IS the liquidity. When a payment routes through you, your balance with the sender increases and your balance with the receiver decreases. Net effect on you: zero (minus your routing fee, which you keep).

The big difference from correspondent banking: anyone can be a routing node. You do not need a banking license to extend personal credit to a friend.

## Top 5 Expected Questions and Responses

Q1: "What about regulatory compliance? MSB licensing?"
A: "Good question. The P2P credit model has different characteristics than money transmission because no fiat moves through the operator. Credit extended between individuals is a different regulatory category. We are consulting with counsel in our target markets (Japan, Singapore, Thailand) where regulatory sandboxes exist. This is an area we take seriously and do not have fully figured out yet."

Q2: "How do you handle disputes?"
A: "Disputes are bilateral, between the two parties on a trust line. If Alice disagrees with Bob about a debt, they resolve it the way they would resolve any financial disagreement with someone they know. We do not intermediate. That is both a feature (no central authority needed) and a limitation (no formal recourse beyond social consequences)."

Q3: "CLS Bank netting comparison is misleading. They net standardized forex obligations."
A: "Fair point. Interbank netting involves standardized, fungible obligations. Consumer debts are more heterogeneous. In eIOU, netting only applies when obligations are denominated in the same unit, which reduces the heterogeneity problem but does not eliminate it. The 15 to 30% netting rate reflects this, it is lower than the 96% banks achieve precisely because consumer credit is messier."

Q4: "What happens when a routing node goes offline?"
A: "Payments that would have routed through that node fail and the system tries alternate paths. If no path exists, the payment fails entirely. This is a real limitation of trust-line routing: the network has no SLA guarantees. Self-hosted Docker nodes running 24/7 help with availability."

Q5: "How do you prevent Sybil attacks?"
A: "Trust lines are the Sybil defense. You only extend credit to people you know and trust. A Sybil attacker could create 1000 nodes, but nobody would give them credit, so they cannot route payments. The trust requirement IS the permissioning layer."

---

# POST 5: r/docker

## Subreddit Intel

Members: ~200K
Self promo rules: All posts must relate to Docker/containerization. Rule #7 is about self-promo (check current wording). Custom container image posts are allowed if they do not violate self-promo rules.
Current mood: Docker alternatives discussion (Podman rising), ACM retrospective on Docker's decade, container best practices.
What gets upvoted: Real compose files, architecture decisions, "how I containerized X" posts, interesting use cases for containers.
What gets removed: Off-topic posts, vague product mentions, anything not actually about Docker.

## Timing

Post on: Tuesday to Thursday
Time: 10 AM to 2 PM EST

## Title

Dockerized a P2P payment node with bundled Tor routing. Open alpha, looking for container architecture feedback.

## Post Body

I just published a Docker image for a P2P credit network node (eIOU). The container runs a payment node that connects to a trust-graph network over Tor. Wanted to share the setup and get feedback on the containerization approach.

What the container does: each instance is a node in a peer-to-peer credit network. Nodes maintain bilateral trust relationships (credit lines) and route payments through chains of trust. All network traffic goes through Tor.

Current setup:

```
docker pull ghcr.io/eiou-org/eiou-docker:latest
```

```yaml
services:
  eiou-node:
    image: ghcr.io/eiou-org/eiou-docker:latest
    volumes:
      - ./data:/app/data
    ports:
      - "8080:8080"
    environment:
      - TOR_SOCKS_PORT=9050
      - API_PORT=8080
    restart: unless-stopped
```

Architecture decisions I am looking for feedback on:

1. Tor bundled vs. separate container: Currently Tor runs inside the same container. This simplifies setup but violates single-responsibility. Would you prefer a separate tor-proxy container with SOCKS5 exposed, or is bundled acceptable for this use case?

2. Database: Using SQLite for local node state (trust lines, balances, transaction history). For a single-node deployment this seems reasonable. Should I also support Postgres via environment variable for people who want external database management?

3. Base image: Currently Alpine-based. Tor and the Python runtime are the main dependencies. Any strong opinions on Alpine vs Debian slim for a container that needs to run Tor?

4. Health endpoint: /health returns node status, active trust line count, last sync timestamp, Tor circuit status. Anything else you would want to see?

5. Backup strategy: All state lives in ./data volume. Is a periodic SQLite dump to a backup volume worth adding as a built-in feature, or should users handle backups externally?

This is open alpha. Things will break. But the container runs and connects to the network.

Full disclosure: I am a founder of eIOU. This is the project's first public Docker release.

GitHub: github.com/eiou-org/eiou-docker

## First Comment (post immediately after)

For context on what the network actually does: it is a credit network where payments route through chains of personal trust. Think of it like a social graph where edges are "I would lend this person up to $X." The system finds paths through the graph for payments and periodically detects circular debts and cancels them (multilateral netting).

All traffic is Tor-routed so the operator cannot correlate senders and receivers. The latency cost is 3 to 8 seconds per operation.

Primarily interested in the Docker/container architecture feedback here. The payment network mechanics are a separate discussion.

## Top 5 Expected Questions and Responses

Q1: "Separate Tor container. Always."
A: "Makes sense for composability. I will add a compose variant with an external tor-proxy service and SOCKS5 configuration via environment variable. Thanks."

Q2: "SQLite in a container is fine for single-node"
A: "That is my thinking. WAL mode is enabled for concurrent reads. The volume mount means data persists across container restarts. Only concern is backup atomicity during writes."

Q3: "Where's the Dockerfile?"
A: "In the repo: github.com/eiou-org/eiou-docker. PRs welcome."

Q4: "Use Podman instead"
A: "The image should work with Podman since it is OCI-compliant. I have not tested it though. If anyone runs it with Podman, I would love to know if it works."

Q5: "What's the image size?"
A: "Share actual size. Working on keeping it minimal. The main bloat is the Tor binary and Python runtime."

---

# POST 6: r/defi

## Subreddit Intel

Members: ~200K
Self promo rules: Must include protocol name in title and disclose risks. Unaudited protocols restricted for self-promo. Discussion framing works better.
Current mood: "DeFi alive or dead?" debates, lending protocol comparisons, yield discussions (5-12% APY on stables as the new normal).
What gets upvoted: Protocol analysis, mechanism comparisons, risk assessments, novel financial primitives explained clearly.
What gets removed: Anonymous team token launches, projects without audit info.

## Timing

Post on: Tuesday to Thursday
Time: 6 to 8 PM UTC (global audience)

## Title

Trust-line routing vs. liquidity pools: two models for decentralized value exchange, very different tradeoffs

## Post Body

Liquidity pools (Uniswap-style AMMs) solved decentralized exchange by removing the need for counterparties. Anyone can provide liquidity, the bonding curve determines price. Elegant and battle-tested.

There is an older model: trust-line routing. Instead of pooling assets, you establish bilateral credit lines with people you trust. Payments route through chains of trust. If A trusts B for $100 and B trusts C for $50, A can pay C up to $50 through B. B earns a small routing fee.

Side-by-side comparison:

Liquidity pools: No trust needed. Permissionless. Capital-inefficient (impermanent loss). Anonymous. Global consensus required.

Trust lines: Trust required. Permissioned per link. Capital-efficient (no idle liquidity). Identity-bound. No consensus mechanism.

The original Ripple (pre-XRP, Ryan Fugger era, 2004) used trust lines. Hawala networks are trust lines. The concept predates blockchains.

Additional mechanism worth examining: multilateral debt netting. The system finds cycles in the debt graph (A owes B owes C owes A) and cancels them. In testing, 15 to 30% of debt volume nets to zero without any settlement.

I think there is room for both models. Trust lines work better for social/community payments where you know counterparties. Pools work better for anonymous high-volume trading.

Honest limitations of the trust-line model:

Requires knowing and trusting your counterparties (not permissionless)
Network density matters hugely (sparse graphs cannot route)
Latency: this particular implementation routes through Tor (3 to 8 seconds per operation)
No formal security audit
Small network, open alpha, pre-revenue
Credit risk is real and social, not protocol-enforced

Risks: If someone in a routing chain defaults on their trust line, the payment fails and debts may not settle. There is no liquidation mechanism or protocol-level insurance.

Full disclosure: I work on eIOU, which implements trust-line routing with Tor privacy. Docker image is open alpha, Android app in closed beta. This is NOT a token launch, investment opportunity, or DeFi protocol in the traditional sense. No blockchain, no token, no yield.

GitHub: github.com/eiou-org/eiou-docker

## First Comment (post immediately after)

To preempt the obvious question: "If there is no token, how is this DeFi?"

It is decentralized finance in the literal sense: no central intermediary, no bank, no blockchain required for the core payment routing. But it is not DeFi in the crypto ecosystem sense of yield farming, governance tokens, or on-chain protocols.

The relevance to this sub is the mechanism comparison. Trust-line routing is a competing primitive to AMM liquidity pools. Both solve "how do you exchange value without a central intermediary" but with completely different assumptions and tradeoffs.

I think the DeFi ecosystem has converged heavily on the pool model and might benefit from examining alternatives, even ones that require trust as an input.

## Top 5 Expected Questions and Responses

Q1: "If there's no token, how is this DeFi?"
A: (Covered in first comment above)

Q2: "No audit means do not trust"
A: "Agree that audits matter. We are pre-revenue and open alpha. A formal audit has not happened yet. That is a limitation I am transparent about. The Docker node is open source so you can audit the code yourself: github.com/eiou-org/eiou-docker"

Q3: "This does not scale"
A: "It scales differently than AMMs. Adding nodes increases routing options but also increases pathfinding complexity. For community-scale networks (hundreds to low thousands of nodes), it works. For millions, the routing algorithm needs optimization. Scaling is an open research problem."

Q4: "What prevents someone from extending fake trust lines?"
A: "Trust lines represent real credit risk. If you extend a $100 trust line to someone and they use it, you are owed $100. There is no incentive to create fake trust lines because you would be creating real financial exposure with no upside."

Q5: "Why would I use this instead of just paying with crypto?"
A: "Different use case. Crypto requires both parties to hold and manage tokens. This uses credit denominated in any unit (USD, EUR, hours of work, whatever both parties agree on). It is closer to 'I owe you' tracking with automatic routing and netting than it is to a cryptocurrency."

---

# POST 7: r/Entrepreneur

## Subreddit Intel

Members: ~3.5M
Self promo rules: "Promotion of products and services is not allowed." This is explicit and enforced. Must frame entirely as a discussion about entrepreneurial learnings. No links to product in post body.
Current mood: Story-based posts, lessons learned, honest startup reflections.
What gets upvoted: "Here is what I learned" posts, honest failure analysis, counterintuitive business decisions, posts that teach without selling.
What gets removed: Product pitches, "check out my startup" posts, anything with a call to action.

## Timing

Post on: Tuesday to Thursday
Time: 8 to 10 AM EST

## Title

I chose to make my payment app deliberately slower than every competitor. Here is what that taught me about product tradeoffs.

## Post Body

Every payment app in the world is racing to be faster. Stripe fights for milliseconds. Apple Pay is near-instant. The entire industry optimizes for speed.

I went the other direction. My payment network adds 3 to 8 seconds of latency to every single operation, on purpose.

The reason: all traffic routes through Tor (the onion routing network). This means the operator (me) literally cannot see who pays whom. Requests arrive through multiple encrypted relays. I cannot build a social graph of my users even if I wanted to.

What I learned from making this choice:

1. Forcing a constraint reveals your real use case. When your app is slow, you quickly learn what it CANNOT be. It cannot be point of sale. It cannot replace tap-to-pay. What remains: settling debts between friends, paying freelancers, managing ongoing credit relationships. These are transactions where 5 seconds is irrelevant.

2. Privacy as differentiation is real but narrow. Most people do not care about payment privacy. But the people who do care, care intensely. Expats, freelancers, people in countries with financial surveillance, privacy-conscious users globally. It is a passionate niche, not a mass market (at least not yet).

3. "Worse" can be "better" for a specific audience. Self-hosting communities love that each user runs their own node. Privacy advocates love that the operator is blind to the social graph. These groups tolerate latency that mainstream users would never accept.

4. Cultural fit matters more than feature lists. We are targeting Japan, Singapore, and Thailand because these markets have centuries of informal credit culture (tanomoshi-ko, chit funds, hui). The concept of trusted lending circles is already familiar. The software is new, the behavior is not.

Current state: pre-revenue, open alpha. The cold start problem for a payment network is brutal. You need density before routing works, but you need routing to work before users see value.

The biggest open question: is "slower but private" a real market position, or am I building something only I want?

Full disclosure: I am a founder of eIOU. Not asking for users or investment in this post. Sharing the tradeoff because I think it is an interesting strategic decision regardless of whether it works.

## First Comment (do NOT post unless discussion takes off)

Only if engagement is high: "For anyone curious about the specific mechanism: the app uses trust-graph routing. You set credit limits with people you know. Payments find paths through chains of trust. The system also detects circular debts and cancels them automatically (called 'chain drops'). In testing, 15 to 30% of total debt disappears through netting. GitHub: github.com/eiou-org/eiou-docker"

## Top 5 Expected Questions and Responses

Q1: "How do you make money?"
A: "Honestly, pre-revenue and still working on it. Exploring routing fees, premium features, enterprise API. Have not committed to a model. That is a risk and I know it."

Q2: "This sounds like a pyramid scheme"
A: "I understand the concern. Key differences: no token, no recruitment incentive, no returns promised. It is a credit network. You lend to people you trust, they might route payments through your credit line and you earn a small fee. More like being a micro-lender for your friends than a scheme."

Q3: "Nobody cares about payment privacy"
A: "Most people do not, and I agree that is a challenge. The people who do care are a small but intense market: expats, freelancers, privacy advocates, people in countries with financial surveillance. The question is whether that niche is large enough to sustain a business."

Q4: "What happens when someone does not pay?"
A: "Same thing as when a friend does not pay you back in real life. You reduce or remove their credit limit. The system does not enforce repayment; your social relationship does. That is the design."

Q5: "Have you talked to actual users?"
A: "Yes. The Android app is in closed beta with real users. The feedback is that the concept resonates, the UX needs work, and the network is too small for routing to be useful yet. All fair."

---

# POST 8: r/startups

## Subreddit Intel

Members: ~1.3M
Self promo rules: Has designated "Share Your Startup" thread (quarterly, latest Jan 2026). Standalone promotional posts are not allowed. Discussion posts about startup challenges are fine with disclosure.
Current mood: EU AI Act compliance discussions, practical startup operations.
What gets upvoted: Honest cold-start problem analysis, strategic decision posts, "here is how we chose our market" posts.
What gets removed: Standalone product pitches, "check out my app" posts outside designated threads.

## Timing

For the quarterly thread: Whenever the next one is posted (check r/startups for timing)
For a discussion post: Tuesday to Thursday, 9 to 11 AM EST

## Option A: Quarterly "Share Your Startup" Thread Submission

### Format

URL: eiou.org

Purpose: Peer-to-peer credit network where payments route through chains of personal trust instead of banks or blockchains.

Stage: Open alpha (Docker node), closed beta (Android app). Pre-revenue.

How it works: You set credit limits with people you trust. Payments find paths through the network. If you trust Alice for $200 and Alice trusts Bob for $100, you can pay Bob up to $100 through Alice. The network handles routing automatically.

Two differentiating features:

Chain drops: automatic multilateral debt netting. If A owes B owes C owes A, the system detects the cycle and cancels the debts. In testing, 15 to 30% of debt volume disappears.

Tor by default: all traffic routes through Tor. We cannot see who pays whom even if we wanted to. Tradeoff: 3 to 8 seconds latency.

Target markets: Japan, Singapore, Thailand. These markets have deep cultural traditions of informal credit networks (tanomoshi-ko, chit funds, hui).

Looking for: Technical feedback on trust-graph architecture. Beta testers, especially in Asia. Infrastructure contributors (Docker node is open source).

Honest limitations: Tiny network (cold start problem). Tor latency. No iOS. Pre-revenue with no clear monetization. UX needs polish.

GitHub: github.com/eiou-org/eiou-docker

Full disclosure: I am a founder.

## Option B: Discussion Post (Cold Start Problem)

### Title

How do you solve the cold start problem for a network that needs density to work?

### Post Body

Building a P2P credit network (eIOU, full disclosure: founder) where payments route through chains of personal trust. The value gets better with more connections, but the first 100 users can barely route payments because the trust graph is too sparse.

Classic chicken and egg, but worse because each user needs multiple trusted connections, not just one.

Strategies we are testing:

Geographic clustering: focus on small friend groups in one city rather than spreading globally. Get 20 people in one neighborhood before expanding.

Use case focus: target specific scenarios (splitting rent, freelancer payments) where you only need 2 to 5 connected people for value.

Cultural fit: launch in markets with existing informal credit culture (Japan, Singapore, Thailand) where the concept is already familiar.

Docker self-hosting: let technical users run their own nodes. Even if the user count is small, self-hosters tend to stay and build. Open alpha just launched.

For founders who have solved network density problems: what actually worked? The standard "get one side first" playbook does not apply because there are not two sides. It is a graph.

## Top 5 Expected Questions and Responses

Q1: "What is your monetization strategy?"
A: "Honestly still figuring it out. Possible models: routing fees, premium node features, enterprise API. We are focused on getting the network to critical density before monetizing. That is a risk."

Q2: "Why not just use Venmo/Wise/crypto?"
A: "Different model. Those move money through centralized intermediaries. eIOU routes credit through people who trust each other. No bank account needed for peer relationships. The netting feature is the key difference: circular debts cancel automatically."

Q3: "This needs network effects. How do you get there?"
A: "Honest answer: we do not know yet. We are testing geographic clustering and cultural-fit markets. If you have solved this kind of problem, I would genuinely love to hear how."

Q4: "What is your team?"
A: Share honestly. Acknowledge gaps.

Q5: "Have you raised?"
A: "Pre-revenue, early stage. Open to conversations but not actively raising at this moment. Focused on proving the network can reach density."

---

# POST 9: r/digitalnomad

## Subreddit Intel

Members: ~2.5M
Self promo rules: Moderate. Genuine tools for nomads are welcomed if framed as a real discussion. Blatant promotion gets downvoted.
Current mood: Cross-border payment friction is a perennial topic. Wise dominance. Southeast Asia is the most discussed region.
What gets upvoted: Personal experience posts, "here is how I solved X problem" stories, tools with honest reviews, discussions about specific countries.
What gets removed: Pure ads, affiliate spam.

## Timing

Post on: Tuesday to Thursday
Time: 8 to 11 AM EST (catches US and European nomads; Asia nomads browse in their evening)

## Title

For nomads in Southeast Asia: how do you handle the informal IOUs that pile up across friend groups and collaborators?

## Post Body

Been spending time in Japan, Singapore, and Thailand. One thing I keep running into with other nomads: the messy web of informal credit.

Not the big transfers (Wise handles those). The smaller, ongoing stuff:

Splitting expenses with other nomads when you are using different currencies
The running tab with collaborators ("I covered coworking, you get dinner")
Informal credit with local freelancers and assistants
The IOUs that pile up in friend groups where everyone owes everyone something

Existing tools handle point-to-point transfers fine. What they do not handle:

Netting across a group: If you owe Alice $30, Alice owes Bob $20, and Bob owes you $25, that is three separate Wise transfers. In a credit network, most of that could cancel out and barely any money moves.

Privacy from the platform: Every Wise/PayPal transfer creates a centralized record. For nomads navigating complex tax residency situations, having one company hold your entire financial social graph can be uncomfortable.

I built a tool for this (eIOU, full disclosure: founder). It is a credit network where you set trust lines with people you know. Payments route through chains of trust. Circular debts cancel automatically ("chain drops"). All traffic goes through Tor for privacy.

Docker image just went public (open alpha): github.com/eiou-org/eiou-docker
Android app on Google Play (closed beta).

Limitations I will own:

3 to 8 seconds per operation because of Tor. Not instant.
Android only for the app. Docker node for self-hosters.
The network is tiny right now.
Pre-revenue.
Only useful if the people you transact with are also on it.

For nomads who manage a lot of informal financial relationships: does this pattern resonate, or is Wise plus Splitwise plus cash good enough?

## First Comment (post immediately after)

Quick note on the Southeast Asia angle: Japan has tanomoshi-ko (traditional rotating credit associations), Singapore has chit funds, Vietnam and Thailand have hui. These are centuries-old systems where groups of people pool money based on mutual trust.

eIOU is basically a digital version of that pattern, with automatic path-finding (you do not need to manually coordinate who owes whom) and debt netting (circular debts cancel without anyone doing the math).

The Docker node means you can run your own instance on a VPS wherever you are. No dependence on our servers.

## Top 5 Expected Questions and Responses

Q1: "Just use Wise plus Splitwise"
A: "That combo works for direct transfers and tracking splits. The difference is automatic netting across a network (circular debts cancel without anyone calculating) and Tor privacy (no centralized financial graph). If those do not matter to you, existing tools are honestly fine."

Q2: "Is this for tax evasion?"
A: "No. All debts and credits are traceable by the parties involved for their own records. The Tor routing prevents the platform operator from seeing the full graph, but individual users have complete transaction history for tax purposes. Privacy from the platform is different from anonymity from authorities."

Q3: "I would use this in Bali/Chiang Mai/Lisbon"
A: "Those are exactly the nomad clusters where a small group of connected users could make this work. The key is getting 5 to 10 people who transact regularly. Happy to share the beta link if you want to try it with your crew."

Q4: "Why would I run a Docker node as a nomad?"
A: "If you are already running a VPS for other things, the eIOU node adds your own payment endpoint to the network. You do not depend on our infrastructure. For non-technical nomads, the Android app is simpler."

Q5: "What currencies does it support?"
A: "Credit lines can be denominated in any unit both parties agree on: USD, EUR, THB, JPY, hours of work, whatever. The system does not convert currencies; it routes credit. Multi-currency routing is on the roadmap."

---

# ADDITIONAL SUBREDDITS (Lower Priority)

---

# POST 10: r/Ripple / r/XRP (combined approach)

## Timing

Post on: Weekdays, when XRP price is STABLE (not during pumps or dumps)
Time: Variable, avoid volatile periods

## Title (r/Ripple)

The original RipplePay (2004) concept is still being built on. Here is what a trust-line payment network looks like in 2026.

## Title (r/XRP)

Before XRP: what Ryan Fugger's original RipplePay looked like and why someone is still building on that model

## Post Body (same for both, minor adjustments)

Most people here know Ripple through XRP and enterprise payment infrastructure. The project's origins are worth revisiting.

Ryan Fugger created RipplePay in 2004 as a trust-based credit network. No token, no blockchain. Bilateral trust lines between people, with payments routing through chains of trust.

When Jed McCaleb and Chris Larsen joined, the project evolved toward the XRP Ledger and enterprise bank messaging. That was a valid and clearly successful path.

But the original trust-line model was set aside rather than fully explored.

I have been building eIOU, which implements the pre-XRP Ripple concept:

Trust-line routing: payments flow through chains of bilateral trust. Each link has a credit limit. Pathfinding uses graph algorithms.

Chain drops: multilateral debt netting. If A owes B owes C owes A, the cycle cancels. 15 to 30% of debt volume nets to zero in testing.

Tor routing: all traffic through Tor for privacy. Tradeoff: 3 to 8 seconds latency.

Self-hostable: Docker node just went public (open alpha). Each user can run their own payment endpoint.

No blockchain. No token. No validators.

I am not here to say this is better than XRP. Different tools for different purposes. XRP handles institutional settlement. Trust-line networks handle social and community payments.

But I think the original Ripple idea deserved more exploration, and I am curious whether people in this community see value in the trust-line model for peer-to-peer use cases.

Full disclosure: I am a founder of eIOU. Open alpha on Docker, closed beta on Android, pre-revenue.

GitHub: github.com/eiou-org/eiou-docker

## Top 5 Expected Questions and Responses

Q1: "Ripple already has trust lines on XRPL"
A: "True. The difference is architectural: XRPL trust lines operate within a global consensus ledger. eIOU has no global ledger. Each node maintains only its own bilateral relationships. No consensus needed because two people simply agree on a credit limit between themselves."

Q2: "This is irrelevant. XRP has moved past this."
A: "XRP has evolved in a different direction and achieved real institutional adoption. I am not arguing it should go back. I am exploring whether the original model has value for a different, narrower use case: payments between people who know each other."

Q3: "Ryan Fugger sold the project. Move on."
A: "He did, and that was his decision. The trust-line routing concept he published is an idea, not proprietary technology. Building on published ideas is how the field progresses."

Q4: "How is this not just Hawala?"
A: "It is essentially digital hawala with automatic pathfinding and cycle detection. The algorithm handles routing that would be impossible to coordinate manually in groups larger than 5 to 10 people."

Q5: Hostile XRP defense
A: "I respect XRP's success. This is not a competition. Different architecture for different use cases. I am genuinely curious about the community's perspective on trust-line routing, not trying to diminish XRP."

---

# POST 11: r/tor / r/onions (combined approach)

## Timing

Post on: Any weekday
Time: Variable

## Title (r/tor)

Built a P2P payment network that routes all traffic through Tor. Architecture decisions and latency tradeoffs.

## Title (r/onions)

Payment network where each node could be a Tor hidden service. Architecture feedback wanted.

## Post Body (r/tor version)

I work on eIOU, a peer-to-peer credit network. All communication between nodes goes through Tor by default. Docker image just went public (open alpha). Sharing the architecture and what we have learned about latency tradeoffs.

Why Tor for payments:

Payment metadata is the core concern. Even with encrypted transaction data, an operator typically knows: who initiated a payment, who received it, timing patterns, and frequency. That reconstructs a financial social graph.

Tor prevents correlation. Requests arrive through onion routing. We see payments arriving but cannot trace who initiated them or map the full topology.

What we have learned:

Latency: 3 to 8 seconds per operation, depending on circuit quality. Rules out point-of-sale entirely.
Circuit reliability: Occasional failures mean robust retry logic is essential. We do automatic circuit rebuilding on failure.
Hidden services: Exploring whether individual user nodes should be .onion hidden services for fully peer-to-peer routing without clearnet servers.

Limitations we are honest about:

Does not protect against a global passive adversary
Latency makes real-time payments impossible
Tor network capacity constraints apply
Threat model assumes honest-but-curious operator, not malicious

Current state: Docker node (open alpha), Android app (closed beta). Pre-revenue.

Would love feedback on:

Tor configuration optimizations for reducing latency?
Payment nodes as hidden services: worth the added complexity?
Concerns about the threat model we should address?

Full disclosure: I am a founder.
GitHub: github.com/eiou-org/eiou-docker

## Top 5 Expected Questions and Responses

Q1: "Why not I2P?"
A: "Tor has a larger relay network, more battle-tested code, broader adoption. I2P's garlic routing could offer advantages though. Open to hearing arguments for I2P."

Q2: "Tor is compromised by [agency]"
A: "Our threat model does not assume Tor is perfect against state-level adversaries. It prevents the service operator from correlating users. For state-level threats, additional protections are needed. We are transparent about that."

Q3: "What about timing attacks?"
A: Engage technically. Discuss padding or delay strategies implemented.

Q4: "Hidden services on mobile are impractical"
A: "Agree. Battery drain and bandwidth overhead make mobile hidden services a bad idea. The Docker node on always-on hardware is the right target for hidden service capability. The mobile app would connect through Tor to other nodes."

Q5: "What's the actual Tor configuration?"
A: Share relevant configuration details. This community expects technical depth.

---

# POST 12: r/homelab / r/opensource (brief entries)

## r/homelab

Title: Run your own payment node on your homelab: eIOU Docker image in open alpha

Post: Brief version of the r/selfhosted post, emphasizing the homelab angle (Pi-compatible, low resource, fun experiment). Link to GitHub. Full disclosure as founder. Post on Friday (same "new project" caution).

## r/opensource

Title: Open source P2P credit network node with Tor routing. Docker image just went public.

Post: Focus on the open source nature. Link to GitHub repo. Explain the architecture briefly. Ask for code review and contributions. Full disclosure as founder.

---

# POSTING SCHEDULE

## Week 1

Friday: r/selfhosted (MANDATORY Friday for new projects)
Friday: r/docker (same day is fine, different sub)

## Week 2

Tuesday: r/CryptoCurrency
Wednesday: r/fintech
Thursday: r/Entrepreneur (discussion format)

## Week 3

Tuesday: r/defi
Wednesday: r/digitalnomad
Thursday: r/startups (discussion post or quarterly thread)

## Week 4

Monday: r/tor
Tuesday: r/Ripple
Wednesday: r/privacy (discussion only, no product mention)
Thursday: r/homelab, r/opensource

## Notes on Timing

Space posts across weeks. Never post to more than 2 subreddits in the same day.
Reddit's spam detection flags accounts that post to many subs rapidly.
Respond to every comment within 2 hours of posting.
If a post gets removed, message mods politely. Do not repost without permission.

## Best Times to Post (by timezone)

US audience: 9 to 11 AM EST (Tuesday to Thursday)
Global/crypto audience: 6 to 8 PM UTC (catches US evening and Asia morning)
Asia audience: 9 to 11 AM JST / 8 to 10 AM SGT (local business hours)
r/selfhosted and r/docker: 10 AM to 2 PM EST (US-heavy tech audience)

---

# UNIVERSAL Q&A BANK

These questions will come up across multiple subreddits. Consistent answers are essential.

## "Is this a scam / rug pull?"

"Fair skepticism. There is no token to rug. No ICO. No fundraising from users. It is a credit network: you set credit limits with people you personally trust. The risk is credit risk with people you know, the same as lending a friend $50. The Docker node is open source so you can audit the code: github.com/eiou-org/eiou-docker"

## "How is this different from Venmo/Wise/PayPal?"

"Those apps move money through a centralized intermediary. eIOU routes credit through people who trust each other. Three key differences: (1) multilateral netting cancels circular debts without money moving, (2) Tor routing means the operator cannot see the financial social graph, (3) each user can run their own node. The tradeoff is latency and the cold start problem."

## "Why should I trust you?"

"You should not trust me. You should trust the people you choose to set credit lines with. The system is designed so the operator (me) has minimal power: I cannot see who pays whom (Tor), I cannot freeze accounts (each node is sovereign), and I cannot modify balances (bilateral agreement between users). The Docker node is open source."

## "What happens if the company dies?"

"Each node stores its own data. If eIOU the company disappears, your node still has your trust lines and balances. The protocol is open and the Docker image is on GitHub. As long as nodes can reach each other over Tor, the network functions without any central entity."

## "Isn't credit risk dangerous?"

"Yes, credit risk is real. That is why you only extend credit to people you personally know and trust. The credit limit is your maximum exposure. If someone defaults, you lose at most what you agreed to lend them, and you already knew and accepted that risk when you set the limit. This is the same risk as lending a friend money informally, just tracked digitally."

## "Why Tor? Are you hiding something illegal?"

"Tor prevents the platform operator from building a social graph of users' financial relationships. Your landlord, your therapist, your side business: payment metadata reveals all of this. We chose to be architecturally incapable of seeing it. The users themselves have full access to their own transaction history for tax and legal purposes."

---

# PRE-POSTING CHECKLIST

Before every Reddit post, verify:

[ ] Founder affiliation disclosed ("I am a founder" or equivalent)
[ ] Current stage mentioned ("open alpha Docker" / "closed beta Android" / "pre-revenue")
[ ] At least one honest limitation included
[ ] No dashes or asterisks anywhere in the text
[ ] No hype language ("revolutionary" / "game-changing" / "disrupting")
[ ] Subreddit specific rules followed (especially r/selfhosted Friday rule)
[ ] Post is educational/discussion-oriented, not purely promotional
[ ] Prepared to respond to comments for 2+ hours after posting
[ ] Know how to respond to "why not just use X?" for that specific community
[ ] GitHub link included: github.com/eiou-org/eiou-docker
[ ] Not cross-posting the exact same text to multiple subs (mods check)
[ ] Account has sufficient karma and participation history in the target sub (100+ karma, 2-3 weeks of helpful commenting beforehand)
[ ] Checked the sub for any recent similar posts or relevant ongoing discussions to reference

---

# REDDIT ACCOUNT PREPARATION (START BEFORE POSTING)

Do NOT post with a fresh account. Build credibility first.

2 to 3 weeks before the first launch post:

Subscribe to all target subreddits
Comment helpfully 3 to 5 times per day across target subs
Answer questions about payments, graph theory, privacy, Docker, self-hosting
Build to 100+ karma before any eIOU mention
The 90/10 rule: for every 1 post about eIOU, you need 9 posts/comments about other things
Use a professional username (not "eIOU_official" or similar)

---

# CRISIS PLAYBOOK

## If a post gets heavily downvoted:
1. Do not delete it
2. Read every comment to understand why
3. Reply acknowledging the feedback
4. Take a 3-day break from that platform
5. Adjust messaging based on what you learn

## If accused of being a scam:
1. Stay calm
2. Point to: no token, no fundraising, open source Docker node, honest limitations in every post
3. Offer to explain the architecture in detail
4. Message mods to explain you are a real founder with a real (small) project

## If someone finds a real flaw:
1. Thank them publicly
2. Acknowledge the flaw honestly
3. Explain if/how you plan to address it
4. Turn their critique into a future post

## If a post gets removed by mods:
1. Read the removal reason carefully
2. Message mods politely asking for clarification
3. Do not repost without explicit mod permission
4. Adjust the post to comply with rules if given the chance to resubmit

---

END OF DOCUMENT
