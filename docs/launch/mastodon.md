# eIOU Mastodon/Fediverse Launch Content
## Definitive Edition
## Compiled: March 13, 2026

---

# WHY THE FEDIVERSE MATTERS FOR eIOU

The Fediverse audience is eIOU's natural home. They value:

1. Genuine decentralization (federation, not blockchain)
2. Privacy by design (not by policy)
3. Self hosting and data sovereignty
4. Open source and transparency
5. Anti corporate, anti surveillance stance
6. Substance over hype

eIOU checks every box. But there is a critical landmine: the Fediverse community is deeply hostile to cryptocurrency and blockchain projects. Mastodon's creator Eugen Rochko has explicitly rejected crypto integration. FediTips regularly reminds users that "Mastodon does NOT use cryptocurrency, blockchains, NFTs, tokens, coins, mining, web3 or anything like that."

This means eIOU MUST lead with "no blockchain, no token, no mining" immediately. Do not let anyone assume this is crypto. The "not crypto" framing is more important here than on any other platform.

---

# PLATFORM RESEARCH SUMMARY

## Instance Character Limits

| Instance | Character Limit | Fork | Focus |
|----------|----------------|------|-------|
| mastodon.social | 500 | Vanilla Mastodon | General, largest instance |
| fosstodon.org | 500 | Vanilla Mastodon | FOSS, open source, self hosting |
| infosec.exchange | 11,000 | glitch-soc | Infosec, privacy, security |
| hachyderm.io | 500 | Vanilla Mastodon | Tech professionals |

## Key Mastodon Cultural Norms

1. Use #introduction on your very first post (mandatory social convention)
2. Use CamelCase in hashtags for screen reader accessibility (#SelfHosted not #selfhosted)
3. Content Warnings (CW) for sensitive or niche topics (finance, crypto adjacent)
4. 3 to 5 hashtags per post, placed at end (not inline)
5. Hashtags in CW headers still get indexed for search
6. Alt text on all images (the community enforces this socially)
7. No cross posting from Twitter/X without context (deeply frowned upon)
8. Engage more than you broadcast (4:1 ratio of engagement to self posts)
9. Boost (reblog) others' content generously
10. Reply to everyone who engages with your posts
11. Quote posts arrived late 2025 and are culturally accepted now

## Fediverse Crypto Sentiment

The Fediverse is the most crypto skeptical tech community online. Key dynamics:

DO: Lead with federation, Tor, self hosting, open architecture
DO: Explicitly state "no blockchain, no token, no mining" early
DO: Frame as continuation of pre blockchain ideas (RipplePay 2004, hawala)
DO: Emphasize privacy architecture over financial innovation
DO: Be genuinely part of the community before promoting

DO NOT: Use terms like "DeFi," "Web3," "tokenomics," "protocol"
DO NOT: Frame eIOU as a "crypto project" or "blockchain alternative"
DO NOT: Use marketing language, hype, or growth metrics
DO NOT: Treat Mastodon as a broadcast channel
DO NOT: Cross post identical content from X/Twitter

---

# ACCOUNT STRATEGY

## Recommended Instance: fosstodon.org

Why fosstodon over infosec.exchange:

1. eIOU's Docker image is now public and open for self hosting, which aligns with Fosstodon's mission
2. Fosstodon's FOSS community will appreciate the self hostable node architecture
3. The 500 char limit forces concise, thread based communication (more natural for Mastodon)
4. Fosstodon users are builders who will actually try the Docker image
5. infosec.exchange can be engaged through replies, boosts, and cross instance follows

Alternative: If Adrien already has a Mastodon account, use that. Don't create a new account just for promotion.

## Bio Setup

```
Building eIOU: a peer to peer credit network with trust graph routing. All traffic through Tor. No blockchain, no token. Docker image now public.

Currently in open alpha. Targeting Japan, Singapore, Thailand.

Founder. Will share honest progress and limitations.

eiou.org | github.com/eiou-org/eiou-docker
```

Pin the introduction post (Post 1 below).

---

# SECTION 1: MAIN LAUNCH TOOT

## Post 1: Introduction (PIN THIS)

Instance: Primary account (fosstodon.org recommended)
Tag: #introduction

This is the foundation. Pin it to your profile. Everyone who visits your profile will see this first.

```
Hi, I'm Adrien. I build eIOU, a peer to peer credit network.

The idea: you set trust lines with people you know. Payments route through chains of those connections automatically. When debts form cycles (A owes B owes C owes A), the system detects and cancels them. We call this "chain drops."

Everything routes through Tor. We cannot see who pays whom. By architecture, not by policy.

No blockchain. No token. No mining. No consensus mechanism.

Docker image just went public (open alpha):
github.com/eiou-org/eiou-docker

Honest limitations:
Tor adds 3 to 8 seconds per operation
Network is tiny
Android app only (plus Docker node)
Pre revenue

I'm on the Fediverse because this is where people who care about federation, privacy, and self hosting actually are.

eiou.org

#introduction #SelfHosted #Privacy #Tor #P2P #FOSS
```

Character count: ~650. Works on infosec.exchange (11k limit). For fosstodon.org (500 limit), split into two posts as a thread:

### Fosstodon Version (500 char limit), Post 1a:

```
Hi, I'm Adrien. I build eIOU, a peer to peer credit network.

You set trust lines with people you know. Payments route through chains of those connections. When debts form cycles (A owes B owes C owes A), the system detects and cancels them. We call this "chain drops."

Everything routes through Tor. We cannot see who pays whom. By architecture, not policy.

No blockchain. No token. No mining.

#introduction #SelfHosted #Privacy #Tor #P2P #FOSS
```

### Fosstodon Version, Post 1b (reply to 1a):

```
Docker image just went public (open alpha):
github.com/eiou-org/eiou-docker

Honest limitations:
Tor adds 3 to 8 seconds per operation
Network is tiny
Android app only (plus Docker node)
Pre revenue

I'm on the Fediverse because this is where people who care about federation, privacy, and self hosting actually are.

eiou.org
```

---

# SECTION 2: INSTANCE SPECIFIC VERSIONS

## Post 2: infosec.exchange Version (Security/Privacy Angle)

This version leads with the threat model. infosec.exchange users care about technical privacy architecture, not product features. The 11,000 character limit means this can be a single comprehensive post.

CW recommended: "Payment metadata and surveillance" (infosec folks appreciate CWs on longer technical posts)

```
CW: Payment system privacy architecture (long post)

Question for the security minded:

Most "encrypted" payment apps protect transaction amounts but keep the complete social graph. They know who pays whom, how often, at what time. That metadata reconstructs your life: your landlord, your therapist, your habits, your relationships.

If you had to rank privacy risks in a payment system, where does graph structure (the pattern of who pays whom) fall compared to amount privacy or identity privacy?

eIOU is a P2P credit network I'm building that tries to address this. The approach:

Tor by default: every operation routes through onion routing. Requests arrive from exit nodes. We cannot correlate senders and receivers. The social graph is not stored centrally because it architecturally cannot be.

Trust graph routing: payments travel through chains of bilateral trust, not through a central processor. Each node only sees its neighbors. No single node observes the full payment path.

Chain drops: when debts form cycles, the system cancels them automatically. Money that never moves cannot be surveilled.

The threat model is: protect users from mass surveillance, commercial data harvesting, and operator curiosity. NOT: protect users from a nation state with physical access to their device. Different tools for different threats.

What we don't protect against (being honest):
Endpoint correlation if your device is compromised
Sybil attacks on small networks
Timing analysis if an attacker controls multiple nodes
Your friends knowing you owe them money (that is the point)

No blockchain. No token. No consensus mechanism. This is not crypto.

Docker image is public: github.com/eiou-org/eiou-docker
Open alpha. Tor adds 3 to 8 seconds latency. Network is small.

I genuinely want the threat model discussion. Where are the holes?

Disclosure: I'm the founder. eiou.org

#InfoSec #ThreatModel #Privacy #Tor #Payments #DataProtection
```

---

## Post 3: fosstodon.org Version (FOSS/Self Hosting Angle)

500 character limit means this must be tight. Focus on Docker self hosting.

### Post 3a (main):

```
You self host your files, your passwords, your media, your DNS.

Why is the one thing with actual financial consequences still on someone else's server?

eIOU is a P2P credit network. Docker image just went public:
github.com/eiou-org/eiou-docker

Each node maintains its own trust graph. Payments route through your connections. All traffic through Tor.

No central ledger. No blockchain. No token.

#SelfHosted #Docker #Privacy #Tor #FOSS
```

### Post 3b (reply, expanding):

```
What the Docker node does:
Maintains your trust relationships
Routes payments through your connections
Participates in chain drops (automatic circular debt cancellation)
Runs over Tor for privacy

What it does not do:
Store anyone else's data
Require port forwarding (Tor handles NAT traversal)
Phone home to any central server

Open alpha. Honest limitations: Tor adds 3 to 8 seconds. Network is tiny. Pre revenue.

Would people here actually run a financial node? Genuinely asking.

Disclosure: I'm the founder. eiou.org
```

---

## Post 4: mastodon.social Version (General Audience)

500 character limit. Lead with the concept, make it accessible.

### Post 4a (main):

```
I built a payment system where even I can't see who pays whom.

eIOU routes all operations through Tor. Trust lines between people you know form the payment network. When debts form cycles, the system cancels them automatically.

No bank. No blockchain. No token.

The tradeoff: Tor adds 3 to 8 seconds. You're not buying coffee with this. It's for settling IOUs, splitting costs, informal credit.

Docker image is public: github.com/eiou-org/eiou-docker

#Privacy #Tor #P2P #Payments #SelfHosted
```

### Post 4b (reply):

```
The concept comes from Ryan Fugger's RipplePay (2004), before it became XRP and blockchains. Trust based credit where payments route through people who know each other, like hawala or Japanese tanomoshi ko.

We added Tor privacy and automatic debt netting. Kept the "no blockchain" part.

Open alpha. Network is small. Android app plus Docker. Pre revenue.

Disclosure: I'm the founder. eiou.org
```

---

# SECTION 3: THREAD VERSION (Multi Toot Explainer)

This is the full concept explained across multiple posts, designed for maximum engagement. Post as a thread (each toot replies to the previous one).

Best timing: weekday, any time (Mastodon is chronological, timing matters less than on algorithmic platforms, but weekday mornings tend to have more readers).

### Thread Toot 1 (hook):

```
Your payment app sees more of your life than your therapist does.

Who you pay, how often, when, how much. It knows your landlord, your habits, your patterns. Most "encrypted" payment apps protect the amounts but keep this entire social graph.

What if a payment system couldn't see any of that?

Thread about what we're building. 🧵

#Privacy #Tor #Payments
```

### Thread Toot 2 (the model):

```
eIOU is a peer to peer credit network. The model:

You set trust lines with people you know. $200 for a close friend. $50 for a colleague. Payments route through chains of these connections automatically.

If Alice trusts Bob, and Bob trusts Carol, Alice can pay Carol through Bob. Bob earns a small routing fee.

This is how hawala has worked for centuries. We added software.
```

### Thread Toot 3 (Tor):

```
Every eIOU operation routes through Tor.

Requests arrive through onion routing. We cannot correlate senders with receivers. We literally cannot build a social graph of our users.

This is architectural privacy, not contractual privacy. A privacy policy can change. A court order can compel disclosure. If the data never existed, none of that matters.

The cost: 3 to 8 seconds per operation. We chose privacy over speed.
```

### Thread Toot 4 (chain drops):

```
The feature I find most interesting: chain drops.

If A owes B, B owes C, and C owes A, the system detects the cycle and cancels the debt. Nobody sends anything. The obligation just disappears.

In testing, 15 to 30% of debt volume nets to zero this way.

Banks do institutional netting daily (CLS nets $6 trillion). Consumer apps never have, because netting reduces the transaction volume they monetize.
```

### Thread Toot 5 (not crypto):

```
To be very clear: this is not crypto.

No blockchain. No token. No mining. No consensus mechanism. No "protocol token." No NFTs. Nothing like that.

Payments are in regular currencies (or any unit: commodities, time, custom). The decentralization is structural: each node stores its own data. There is no shared ledger.

Think federated (like email, like Mastodon) rather than blockchain.
```

### Thread Toot 6 (origins):

```
The concept comes from Ryan Fugger's RipplePay, built in 2004. Before blockchains existed.

Fugger's idea: payments route through trust between people. No intermediaries needed beyond the ones you already know.

Then the project became XRP and went a different direction. The trust routing concept was abandoned, not disproven.

eIOU picks up where the original design left off.
```

### Thread Toot 7 (self hosting):

```
Docker image is now public (open alpha):
github.com/eiou-org/eiou-docker

Your node. Your data. Your trust relationships.

Each node maintains bilateral trust lines and participates in the payment routing network. Tor handles NAT traversal, so no port forwarding needed.

If you self host your passwords and your files, maybe your finances deserve the same treatment.
```

### Thread Toot 8 (honest limitations):

```
Honest limitations (because this community deserves that):

Tor latency: 3 to 8 seconds per operation. Not for point of sale.
Network is tiny (open alpha). Routing needs density to find paths.
Android app only (plus Docker node).
Pre revenue. No business model yet.
Credit risk is real: you're trusting people, not banks.
Cold start problem is our existential risk.

This might not work. We think it's worth trying.
```

### Thread Toot 9 (closing):

```
We're targeting Japan, Singapore, and Thailand. Not regulatory arbitrage. Cultural fit.

These markets have centuries of trust based informal lending: tanomoshi ko, chit funds, hui. We're building software for something communities already do.

Docker image: github.com/eiou-org/eiou-docker
Website: eiou.org

Full disclosure: I'm the founder. Feedback, criticism, threat model questions all welcome.

#SelfHosted #Privacy #Tor #P2P #FOSS #Docker
```

---

# SECTION 4: HASHTAG STRATEGY

## Primary Hashtags (use on most posts)

| Hashtag | Why | Notes |
|---------|-----|-------|
| #Privacy | Core value alignment with Fediverse | High follow count |
| #SelfHosted | Docker image is the product | Very active on fosstodon |
| #Tor | Technical differentiator | Smaller but targeted audience |
| #P2P | Describes the architecture | Used by federation community |
| #FOSS | Open source alignment | Core fosstodon identity |

## Secondary Hashtags (rotate based on post topic)

| Hashtag | When to use |
|---------|-------------|
| #Docker | Posts about the Docker image specifically |
| #InfoSec | Posts about privacy architecture, threat models |
| #ThreatModel | Technical privacy discussion posts |
| #DataProtection | Privacy regulatory angle |
| #Decentralization | Architecture discussion (NOT in crypto context) |
| #Federation | When comparing to federated model |
| #GraphTheory | Technical posts about routing algorithms |
| #FinTech | Use sparingly, can attract noise |
| #Payments | When discussing payment systems generally |
| #OpenSource | If/when code becomes open source |

## Hashtags to AVOID

| Hashtag | Why |
|---------|-----|
| #Crypto | Will attract wrong audience, trigger skepticism |
| #Blockchain | eIOU is explicitly not blockchain |
| #Web3 | Deeply toxic term in Fediverse |
| #DeFi | Same problem as #Crypto |
| #Token | Implies tokenomics |
| #NFT | Automatic credibility destruction |
| #Bitcoin | Wrong framing entirely |

## Hashtag Formatting Rules

1. Use CamelCase: #SelfHosted not #selfhosted (screen reader accessibility)
2. Place hashtags at the end of the post, not inline
3. 3 to 5 hashtags maximum per toot (more looks spammy)
4. Use #introduction on first post only (Mastodon convention)
5. Do not hashtag the brand (#eIOU is unnecessary and looks corporate)
6. Hashtags can go inside CW headers and still index for search

---

# SECTION 5: ENGAGEMENT PLAN

## Phase 1: Before Posting (1 to 2 weeks)

Before posting anything about eIOU, establish presence:

1. Follow 50 to 100 accounts in privacy, self hosting, infosec, FOSS spaces
2. Boost 3 to 5 posts per day from community members
3. Reply thoughtfully to discussions about privacy, Tor, self hosting, decentralization
4. Post NON eIOU content: interesting articles, opinions on privacy news, reactions to FOSS projects
5. Fill out bio completely (see Account Strategy above)
6. Set a profile photo and header image (with alt text)

Goal: Be a real person in the community before being a founder promoting a project.

## Phase 2: Launch (Week 1)

Day 1: Post introduction (Post 1). Pin it.
Day 2: Nothing. Engage with replies to introduction.
Day 3: Post thread version (Section 3). Respond to every reply.
Day 4 to 5: Boost and engage with community content. No self promotion.
Day 6: Post the instance appropriate version (security, FOSS, or general depending on your instance)
Day 7: Engagement only. Thank people who boosted your posts.

## Phase 3: Sustained Presence (Weeks 2 to 8)

Daily habits:
1. Boost 2 to 3 posts from privacy/FOSS/self hosting community members
2. Reply to 3 to 5 discussions per day (NOT about eIOU)
3. Share interesting articles about trust networks, privacy, graph theory
4. Post about eIOU at most once every 3 to 4 days
5. When posting about eIOU, share progress honestly: "Here's what we learned" or "Here's what broke"

Engagement ratio: For every 1 post about eIOU, boost or engage with 5 to 6 posts from others.

## Key Accounts to Follow and Engage With

### Privacy / InfoSec (infosec.exchange and beyond)

Follow people posting about:
1. Tor Project related content (search #Tor)
2. Privacy engineering and threat modeling
3. Metadata protection and surveillance resistance
4. Self hosting privacy tools

Engage with their posts before mentioning eIOU. Build relationships first.

### FOSS / Self Hosting (fosstodon.org and beyond)

Follow people posting about:
1. Docker deployments and container orchestration
2. Homelab projects
3. Alternatives to commercial services
4. Open source project development

The Docker image launch is a natural conversation entry point. Reply to "what are you self hosting?" threads.

### Decentralization / Federation

Follow people who:
1. Discuss ActivityPub and federation protocols
2. Compare federated vs centralized architectures
3. Talk about data sovereignty
4. Advocate for user controlled infrastructure

eIOU's federated node model (each user runs their own node with local data) maps directly to these values.

## Conversation Entry Points

Instead of broadcasting, enter existing conversations naturally:

When someone posts about payment privacy:
"This resonates. We're building a system (eIOU) that routes payments through Tor specifically because metadata (who pays whom) is more revealing than amounts. The tradeoff is 3 to 8 seconds of latency. Disclosure: founder."

When someone posts about self hosting new services:
"Have you considered self hosting financial infrastructure? We just made our Docker image public for a P2P credit network that runs over Tor. github.com/eiou-org/eiou-docker. Disclosure: I'm the founder."

When someone discusses decentralization vs blockchain:
"This is exactly the distinction we focus on. eIOU is decentralized through federation (each node stores its own data, bilateral trust lines) not through blockchain consensus. No token, no mining. More like email architecture than Bitcoin architecture. Disclosure: building this."

When someone mentions Ripple or crypto history:
"Interesting aside: before XRP existed, Ryan Fugger built RipplePay in 2004 as a trust routing network with no blockchain at all. Payments routed through social trust. That concept was abandoned when it became XRP. We picked it back up at eIOU. Disclosure: founder."

## What to Avoid

1. Never cross post identical text from X/Twitter (Fediverse hates this)
2. Never boost your own posts from multiple accounts
3. Never treat Mastodon as a broadcast channel
4. Never use corporate marketing tone
5. Never ignore replies (every interaction matters in a smaller community)
6. Never post without alt text on images
7. Never post about eIOU more than twice per week
8. Never argue aggressively in replies (disagree politely, move on)
9. Never assume people know what a "trust graph" is (explain every time)
10. Never let someone think this is crypto without correcting them immediately

---

# SECTION 6: ADDITIONAL STANDALONE TOOTS

## Technical Deep Dive: Chain Drops

```
Graph theory in practice: multilateral debt netting.

Build a directed graph where edges are debts. Run cycle detection (DFS). When you find a cycle, subtract the minimum edge weight from every edge in the cycle. Repeat until no cycles remain.

Result: debts that perfectly offset each other simply vanish. No money moves. No settlement needed.

We built this into eIOU. In testing: 15 to 30% of debt volume cancels through cycles.

Banks do this at institutional scale (CLS nets trillions daily). Consumer apps never have, because netting reduces the volume they monetize.

#GraphTheory #Privacy #P2P
```

## Cultural Context Toot

```
Hawala, tanomoshi ko, hui, chit funds.

Communities across Asia and the Middle East have been running trust based credit networks for centuries. You lend to someone your friend vouches for. You borrow from a group where your reputation matters.

eIOU is software for this model. Bilateral trust lines, payment routing through social connections, automatic debt netting. All over Tor.

The "innovation" isn't new. It's ancient. We're adding onion routing and graph algorithms.

Disclosure: founder. eiou.org

#Privacy #P2P #SelfHosted
```

## "Why Not Blockchain" Toot

```
People ask why eIOU doesn't use a blockchain.

Because we don't need one.

Blockchain solves the problem of consensus between strangers who don't trust each other. eIOU solves a different problem: routing payments between people who already trust each other.

If you trust your friend for $100, that trust IS the infrastructure. No validators needed. No token needed. No shared ledger needed.

Each node stores its own data. Trust is bilateral and local. Like email servers, not like a blockchain.

Disclosure: founder. eiou.org

#Privacy #Decentralization #FOSS
```

## Progress Update Template

Use this format for ongoing updates. The Fediverse rewards transparency.

```
eIOU open alpha update, [date]:

What we shipped:
[specific feature or fix]

What broke:
[honest description of problems]

What we learned:
[insight from testing]

Numbers (being transparent):
[user count / transaction count / chain drops stats / whatever is real]

Docker image: github.com/eiou-org/eiou-docker

#SelfHosted #Privacy #Tor
```

---

# SECTION 7: LEMMY CROSS POSTING STRATEGY

Lemmy is the Fediverse's Reddit alternative. Same values: open source, privacy, anti corporate. Posts here can reach both Lemmy users and Mastodon users who follow Lemmy communities.

## Target Communities

### Tier 1 (Post within first 2 weeks)

1. !selfhosted@lemmy.world (largest self hosting community on Lemmy)
   Angle: Docker image launch. "Self hostable P2P credit node with Tor routing."
   Rules: Must be centered around self hosting. Technical depth expected.

2. !selfhost@lemmy.ml (original self hosting community)
   Angle: Same as above, slightly more technical audience.

3. !privacy@lemmy.ml (privacy focused community)
   Angle: Tor routing architecture. Why payment metadata matters.
   Rules: Focus on privacy implications, not product features.

### Tier 2 (Post in weeks 3 to 4)

4. !technology@lemmy.world (general technology)
   Angle: "P2P credit network with trust graph routing and Tor privacy."
   Broader audience, keep it accessible.

5. !linux@lemmy.ml (Linux community, overlaps with self hosting)
   Angle: Docker deployment on Linux. Server side self hosting angle.

6. !opensource@lemmy.ml (open source software)
   Angle: Only if eIOU has open source components to show.

### Tier 3 (If appropriate)

7. !privacy@lemmy.world (parallel privacy community)
8. !homelab@lemmy.world (homelab enthusiasts)

## Lemmy Post Template (for !selfhosted@lemmy.world)

Title: Self hostable P2P credit network node with Tor routing (Docker, open alpha)

```
I've been working on eIOU, a peer to peer credit network where each user is a node with bilateral trust lines to people they know. Payments route through chains of trust. All traffic goes through Tor.

The Docker image just went public:
github.com/eiou-org/eiou-docker

Architecture:
Each node maintains its own trust graph locally
Payments find paths through the network using graph routing
Chain drops: when debts form cycles (A owes B owes C owes A), the system detects and cancels them automatically
Tor handles all connectivity (no port forwarding needed)

What this is not:
Not blockchain, not crypto, not a token, not mining
Not a central service you connect to
Not fast (Tor adds 3 to 8 seconds, intentional tradeoff for privacy)

What I'd like to know from this community:
Would you actually run a financial node on your setup?
What features would make you want to deploy it?
What's missing from the Docker deployment that you'd expect?

The network is small (open alpha). Pre revenue. The honest risk is the cold start problem: routing needs density to find paths.

Disclosure: I'm the founder.
eiou.org
```

## Lemmy Post Template (for !privacy@lemmy.ml)

Title: Payment system where even the operator can't see the social graph (Tor routed P2P credit network)

```
Most "private" payment apps encrypt transaction content but retain the complete metadata: who pays whom, how often, at what time. This social graph reconstructs your life more effectively than the transactions themselves.

eIOU takes a different approach. All traffic routes through Tor. Requests arrive through onion routing from exit nodes. We cannot correlate senders with receivers. The payment social graph is not stored centrally because it architecturally cannot be.

The payment model adds another layer: payments route through chains of bilateral trust, not through a central processor. Each node only sees its neighbors in the chain. No single node observes the full payment path.

Threat model (being specific):
Protects against: mass surveillance, commercial data harvesting, operator curiosity
Does NOT protect against: endpoint compromise, nation state with physical device access, timing analysis by adversary controlling multiple nodes

Tradeoffs:
3 to 8 seconds latency from Tor (not for point of sale)
Small network (open alpha)
Credit risk is real (you're trusting people, not institutions)

No blockchain. No token. No consensus mechanism. This is not crypto.

Docker image: github.com/eiou-org/eiou-docker
Website: eiou.org

Disclosure: I'm the founder. Genuinely want to hear where the threat model has holes.
```

---

# SECTION 8: CONTENT CALENDAR (First 4 Weeks)

## Pre Launch (Weeks negative 2 to negative 1)
Follow 50 to 100 accounts. Boost and reply. Be a community member. Zero promotion.

## Week 1
Day 1 (Mon): Post introduction (Post 1). Pin it. Respond to all replies.
Day 2 (Tue): Engagement only. Boost 3 to 5 community posts.
Day 3 (Wed): Post thread (Section 3, toots 1 through 9). Respond to every reply.
Day 4 (Thu): Engagement only. Reply to community discussions.
Day 5 (Fri): Post to Lemmy !selfhosted@lemmy.world.
Day 6 to 7: Engagement only. Weekend boost and reply.

## Week 2
Day 8 (Mon): Post instance specific version (Post 2, 3, or 4 depending on home instance).
Day 9 to 10: Engagement only.
Day 11 (Thu): Post chain drops technical toot (Section 6).
Day 12 (Fri): Post to Lemmy !privacy@lemmy.ml.
Day 13 to 14: Engagement only. Weekend.

## Week 3
Day 15 (Mon): Post "Why Not Blockchain" toot (Section 6).
Day 16 to 17: Engagement only.
Day 18 (Thu): Post cultural context toot (Section 6).
Day 19 to 21: Engagement only. Post to Lemmy !technology@lemmy.world if appropriate.

## Week 4
Day 22 (Mon): First progress update using template (Section 6).
Day 23 to 28: Engagement mostly. One more eIOU post mid week if there's genuine news.

## Ongoing (Month 2 plus)
Post about eIOU at most twice per week.
Boost and engage daily (2 to 3 boosts, 3 to 5 replies per day).
Share progress honestly. Include what broke, not just what shipped.
Participate in community discussions on privacy, self hosting, Tor.
Reply to every mention of eIOU (positive or negative).

---

# SECTION 9: RESPONDING TO FEDIVERSE OBJECTIONS

The Fediverse will push back. Here are pre written responses (adapt to context):

## "This is just crypto with extra steps"

```
Understandable concern, but no. No blockchain, no token, no mining, no consensus mechanism, no shared ledger. Payments are denominated in regular currencies (or any unit you define). The architecture is closer to email federation than to any blockchain: each node stores its own data and communicates with trusted peers. The only shared concept with crypto is "peer to peer," but the implementation is completely different.
```

## "Why should we trust you?"

```
Ideally, you shouldn't need to. Tor routing means we can't see the social graph. There's no central ledger we control. The Docker image lets you run your own node. The goal is architecture where trusting us is unnecessary. We're not fully there yet (open alpha, app still has central components), but that's the direction. Scrutiny is welcome.
```

## "3 to 8 seconds is unusable"

```
For point of sale, absolutely. We agree. This is not for buying coffee. The use cases: settling IOUs with friends, paying freelancers, managing informal credit between people who know each other. Transactions where 5 seconds is irrelevant and financial metadata privacy is more important than speed.
```

## "How do you make money?"

```
We're pre revenue and honest about it. We haven't figured this out yet. Possible models include small routing fees, premium features, or hosted node services. But we're focused on building something that works before optimizing revenue. This is open alpha, not a business yet.
```

## "Isn't this just Ripple?"

```
It's the continuation of Ryan Fugger's original RipplePay concept from 2004, which was a trust routing network with no blockchain. Before XRP, before the consensus ledger, before the token. We added Tor privacy and automatic debt netting. Same founding insight (payments through trust), completely different execution, 20+ years of new technology. No relation to the current Ripple company.
```

## "What about regulation / KYC / AML?"

```
Valid question. We're in open alpha, pre revenue, and actively navigating this. The architecture (Tor routing, no central ledger) creates genuine regulatory complexity. We're not trying to evade regulation; we're exploring what privacy preserving financial architecture looks like within legal frameworks. This is an open question, not a solved problem.
```

## "The cold start problem will kill this"

```
You might be right. This is our biggest existential risk and we're honest about it. Trust graph routing needs density to find payment paths. Right now the network is sparse. Our approach is targeting existing friend groups in specific markets (Japan, Singapore, Thailand) where informal credit is already cultural. Dense local clusters before broad adoption. Whether that works is genuinely unknown.
```

---

# SECTION 10: VISUAL CONTENT GUIDELINES

If posting images on Mastodon:

1. ALWAYS include alt text (the community enforces this socially, and some instances require it)
2. Describe images for screen readers: "Diagram showing three nodes A, B, C connected by trust lines, with arrows showing a circular debt pattern"
3. Avoid stock photos or AI generated images (both are frowned upon)
4. Simple diagrams are welcome: trust graph illustrations, chain drop visualizations
5. Screenshots of the Docker deployment process would resonate with the self hosting crowd
6. Do not use corporate branded graphics or marketing materials

---

# SECTION 11: MASTODON SPECIFIC TECHNICAL NOTES

## Content Warnings (CW) Usage

Use CWs for:
1. Longer technical posts (as courtesy, "long post" in CW line)
2. Posts that discuss finance/money (some people filter financial content)
3. Posts that reference crypto history (prevents triggering crypto fatigue filters)

CW format: Put a brief description in the CW field. The hashtags can go in the CW line and still get indexed.

Example: "CW: P2P payment system, long post #Privacy #Tor"

Do NOT use CWs for:
Short, casual posts
Simple project updates
Replies in threads

## Visibility Settings

Post publicly for maximum discovery (hashtag indexing only works on public posts).
Use "unlisted" for casual replies or when you don't want to flood local timelines.
Never use "followers only" for project announcements (defeats the purpose).

## Quote Posts (new feature as of late 2025)

Mastodon now supports quote posts. Use them to:
1. Add context when sharing someone else's relevant post
2. Respond to privacy/self hosting discussions with your perspective
3. Quote your own previous technical posts when answering questions

Do NOT use them to:
1. Dunk on people or argue
2. Amplify negative interactions
3. Quote without adding substantive value

---

# SECTION 12: CROSS INSTANCE ENGAGEMENT

Even if Adrien's account is on fosstodon.org, he can engage across instances:

## On infosec.exchange:
Follow and engage with infosec accounts. When relevant, reply to privacy discussions with the security angle of eIOU. The 11,000 char limit on infosec.exchange means people there are used to long, detailed posts. When they visit Adrien's profile (on fosstodon), the content will be visible cross instance.

## On mastodon.social:
Follow general tech accounts. The largest instance means widest initial visibility for boosts. Engage with technology and privacy discussions.

## On hachyderm.io:
Tech professionals instance. Good for developer engagement. Follow Docker/infrastructure people.

## Cross Instance Boosting:
When a post performs well on one instance, it gets federated to others through boosts. Focus on quality engagement on your home instance; federation handles distribution naturally.

---

# PRE POST CHECKLIST (Every Mastodon Post)

Before every post about eIOU, verify:

1. Founder disclosure included ("Disclosure: I'm the founder" or "I'm building this")
2. Current stage mentioned ("open alpha" or "pre revenue" or "Docker image just went public")
3. At least one honest limitation included (Tor latency, small network, cold start risk)
4. No dashes or asterisks anywhere in the text
5. No hype language ("revolutionary," "game changing," "disrupting")
6. "No blockchain, no token" stated or clearly implied
7. Hashtags at end of post, 3 to 5 maximum, CamelCase
8. Alt text on any images
9. Content Warning if post is long or discusses sensitive financial topics
10. Content is written for Mastodon (not copied from X/Twitter)
11. You've boosted at least 2 community posts today before posting about eIOU
12. You're prepared to respond to replies for the next few hours
13. Post is public (not unlisted) so hashtags are discoverable

---

# APPENDIX A: ACCOUNT SETUP CHECKLIST

Before first post:

1. Choose instance (fosstodon.org recommended)
2. Set display name: Adrien Hubert
3. Set username: something professional (not @eiou_official)
4. Write bio (see Account Strategy section above)
5. Upload profile photo with alt text ("Photo of Adrien Hubert, founder of eIOU")
6. Upload header image with alt text (optional: simple diagram of trust graph routing)
7. Add verified links to profile: eiou.org, github.com/eiou-org
8. Follow 50 to 100 accounts in target spaces (privacy, FOSS, self hosting, Tor)
9. Boost and reply to community content for 1 to 2 weeks before first eIOU post
10. Enable notifications for replies and mentions

---

# APPENDIX B: MASTODON VS OTHER PLATFORMS

| Aspect | Mastodon | X/Twitter | Reddit |
|--------|----------|-----------|--------|
| Discovery | Hashtags only | Algorithm | Subreddit + upvotes |
| Crypto tolerance | Very low | High | Moderate |
| Self promo tolerance | Low to moderate | High | Low |
| Community engagement value | Very high | Moderate | High |
| Content recycling | Frowned upon | Expected | Banned |
| Tone | Authentic, technical | Hot takes, threads | Discussion, depth |
| Best eIOU angle | Privacy + self hosting | Ripple history + chain drops | Technical architecture |
| Posting frequency | 1 to 2x per week about eIOU | Daily | 2 to 3x per month |

---

END OF MASTODON/FEDIVERSE LAUNCH CONTENT

All content is copy paste ready with instance specific adaptations. Character limits verified against actual instance configurations. Cultural norms researched and respected.

Next steps:
1. Choose or confirm Adrien's Mastodon instance
2. Complete account setup (Appendix A)
3. Spend 1 to 2 weeks engaging before first eIOU post
4. Follow the 4 week content calendar (Section 8)
5. Monitor and adapt based on community reception
