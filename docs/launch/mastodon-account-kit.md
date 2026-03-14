# eIOU Mastodon Account Kit
## Everything needed to run the @eIOU Mastodon presence

---

# ACCOUNT SETUP

## Recommended Instance
fosstodon.org (FOSS/open source focused, aligns perfectly with eIOU)
Alternative: hachyderm.io (tech professionals) or infosec.exchange (security/privacy, 11K char limit)

## Display Name
eIOU

## Bio (160 chars on most instances)
```
Peer-to-peer credit network. Send money through people you trust. No banks, no blockchain, no tokens. Open source alpha. Run your own node.
```

## Profile Fields (up to 4 on most instances)
```
Website: eiou.org
GitHub: github.com/eiou-org/eiou-docker
Docker Hub: hub.docker.com/u/eiou
Android Alpha: Play Store (search "eIOU")
```

## Avatar
Use the eIOU logo

## Header Image
Trust graph diagram showing nodes and payment paths

---

# KNOWLEDGE BASE

## What is eIOU?

eIOU is a peer-to-peer credit network where payments route through people who already trust each other. Instead of banks, payments travel through chains of personal trust. Each person sets their own credit limits and routing fees.

## How it works (simple version)

Alice wants to pay Daniel but doesn't know him. Alice trusts Bob, Bob trusts Carol, Carol trusts Daniel. Alice sends $50 and it routes through the chain. Bob and Carol each earn a small fee. Daniel gets paid. No bank involved.

## How to get started

One person on the network trusts you? You're in. No bank account, no credit check, no KYC. That's the entire onramp.

## How to run a node

```
git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker
docker compose up -d
```

Container auto generates a wallet, starts Tor, initializes everything. Ready in about 2 minutes.

## Key features

1. Trust graph routing: payments find the cheapest path through social connections. Fees as low as 0% for close contacts.

2. Debt netting: if A owes B, B owes C, and C owes A, the system detects the cycle and cancels the debt. In testing, 15 to 30% of debt disappears without anyone sending money.

3. Tor privacy: all traffic routes through onion routing. Nobody, including us, can see who pays whom. Tradeoff: 3 to 8 seconds latency.

4. Any denomination: dollars, euros, stablecoins, hours, commodities, custom units.

5. Self hosted: each node stores its own data. No central server, no central authority.

## What eIOU is NOT

NOT a cryptocurrency. NOT a blockchain. NOT a token. No coins are mined, minted, or generated. eIOU tracks bilateral IOUs routed through a social graph.

This distinction is CRITICAL on Mastodon. The Fediverse is deeply hostile to crypto. Always lead with what eIOU is NOT before explaining what it is.

## Why the network works when it's small

Research on the strength of weak ties (Granovetter, 1973) and six degrees of separation shows that small, loosely connected networks can reach surprisingly far. A few hundred active nodes with diverse connections can create viable payment paths across continents.

## Current status

Open source alpha. Docker image is public. Android app on Google Play. The network is small. Actively developing. Pre revenue.

## Technical stack

PHP backend, MySQL/SQLite, Docker containerized, Tor integrated, REST API, web GUI, CLI interface. Apache 2.0 license.

## Links

Website: eiou.org
GitHub: github.com/eiou-org/eiou-docker
Docker Hub: hub.docker.com/u/eiou
Android: Google Play (search "eIOU")

---

# MASTODON CULTURE RULES (MUST FOLLOW)

1. NEVER call eIOU crypto, blockchain, web3, or DeFi. The Fediverse will block you instantly.

2. Use CamelCase in all hashtags for screen reader accessibility. #SelfHosted not #selfhosted. #OpenSource not #opensource.

3. Add alt text to every image. No exceptions. The community enforces this.

4. Use Content Warnings (CW) when posting about finance/money topics. Header like "fintech / P2P payments" is sufficient.

5. First post MUST use the #introduction hashtag. This is a social convention on Mastodon.

6. Do NOT cross post from Twitter/X. Write native Mastodon content. The community hates cross posts.

7. Engage more than you broadcast. For every post about eIOU, make 4 replies/boosts on other people's content. Boost generously.

8. Reply to everyone who engages with your posts. Every single person.

9. 3 to 5 hashtags per post, placed at the end (not inline).

10. No hype language. No "revolutionary." No "game changing." No "disrupting." Substance only.

11. Be honest about limitations. Always mention: open alpha, small network, Tor adds latency.

---

# HASHTAG STRATEGY

## Primary (use on most posts)
#SelfHosted #OpenSource #Privacy #P2P #FinTech

## Secondary (rotate based on topic)
#Tor #Docker #DecentralizedFinance #Payments #FreeSoftware #DataSovereignty #FOSS

## NEVER USE
#Crypto #Web3 #DeFi #Blockchain #NFT #Token
(These will get you muted/blocked by most of the Fediverse)

---

# RESPONDING TO COMMON QUESTIONS

Q: "Isn't this just crypto?"
A: No. eIOU has no blockchain, no token, no mining, no consensus mechanism. It tracks bilateral IOUs between real people and routes payments through chains of trust. Think of it as software for something communities have done for centuries: hawala, tanomoshi-ko, chit funds.

Q: "How is this different from Venmo/PayPal?"
A: Venmo routes through banks and takes a cut. eIOU routes through people you know. Your contacts set the fees, often zero. And Venmo sees every transaction. eIOU routes through Tor, so we literally cannot see who pays whom.

Q: "What about fraud? Someone could just not pay."
A: Credit risk is real and it's social. You only extend credit to people you actually trust. If someone defaults, that's between you and them, same as lending cash to a friend. The difference is eIOU tracks it and the network remembers.

Q: "How do you make money?"
A: We're pre revenue and figuring this out. The software is open source and self hostable. Possible paths include hosted node services, premium features, or enterprise deployments. We don't take a cut of transactions.

Q: "Why Tor? Isn't that for illegal stuff?"
A: Tor is for privacy. Payment metadata (who pays whom, when, how often) reveals your landlord, your therapist, your habits. We route through Tor so even we can't build a social graph of our users. The tradeoff is 3 to 8 seconds of latency, which is fine for settling IOUs but not for buying coffee.

Q: "Can I run this on my Raspberry Pi?"
A: The Docker image runs on any system with Docker. Raspberry Pi 4 with 4GB RAM should work. We haven't tested extensively on ARM yet so feedback is welcome.

Q: "Is this actually open source?"
A: Yes. Apache 2.0 license. Full source code on GitHub: github.com/eiou-org/eiou-docker. Pull it, read it, fork it, run it.

---

# TONE GUIDE

Voice: Technical but accessible. Honest. Slightly informal. Excited about the technology but not salesy.

DO: Share technical details. Ask for feedback. Admit limitations. Thank people who engage. Boost related projects. Discuss the ideas behind eIOU (trust networks, graph theory, payment routing, privacy).

DO NOT: Hype. Shill. Promise returns. Compare to crypto projects. Use corporate language. Ignore criticism. Post and disappear.

Think of this account as a builder sharing their work, not a company doing marketing.

---

# WEEKLY POSTING RHYTHM

Monday: Boost/engage with other FOSS or privacy projects
Tuesday: Technical post (how something works, architecture decision, code snippet)
Wednesday: Engage only (reply to others, no original posts)
Thursday: Community post (asking for feedback, sharing a decision, behind the scenes)
Friday: Boost/engage with self hosting or privacy content
Weekend: Light engagement, no original posts unless responding

2 to 3 original posts per week maximum. The rest is engagement.
