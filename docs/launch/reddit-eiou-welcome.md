# r/eIOU Welcome Post

## Title
Welcome to r/eIOU

## Body
eIOU is a peer-to-peer credit network where payments route through people who already trust each other.

Instead of sending money through banks, eIOU finds a path through your existing relationships. You trust your friend for $200, your friend trusts their colleague for $100, and value can move through that chain. Each person sets their own credit limits and fees.

Getting started takes one thing: someone who trusts you. No bank account, no credit check, no KYC. One person opens a trust line with you and you're on the network.

HOW A PAYMENT WORKS

Alice wants to pay Daniel but doesn't know him. Alice trusts Bob, Bob trusts Carol, Carol trusts Daniel. Alice sends $50 and it routes through the chain. Bob and Carol each earn a small fee for routing. Daniel gets paid. No bank involved.

The network doesn't need millions of users. Six degrees of separation means a few hundred well connected nodes can route payments across continents. You just need a chain of trust that connects you.

WHAT HAPPENS AUTOMATICALLY

P2P transfers: payments find the cheapest path through your social connections. Each person in the chain sets their own fee (often zero for close contacts).

Debt netting: if A owes B, B owes C, and C owes A, the system detects the cycle and cancels the debt. Circular debt disappears without anyone sending money.

Tor privacy: all traffic routes through onion routing. Nobody, including us, can see who pays whom.

Debt can be denominated in anything: dollars, euros, stablecoins, hours, or custom units. eIOU is not a cryptocurrency. No tokens, no coins, no mining.

RUN YOUR OWN NODE

git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker
docker compose up -d

The container generates a wallet, starts Tor, and initializes everything. Ready in about 2 minutes.

LINKS

Website: eiou.org
GitHub: github.com/eiou-org/eiou-docker
Android alpha: search "eIOU" on Google Play
Docker Hub: hub.docker.com/u/eiou

This is open source alpha. Use this subreddit to ask questions, share feedback, report issues, or just say hi. We're building this in the open and every piece of feedback shapes what eIOU becomes.
