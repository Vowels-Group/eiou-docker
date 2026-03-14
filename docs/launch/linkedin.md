# LinkedIn Launch Post

We just open sourced eIOU.

eIOU is a peer-to-peer credit network. Instead of routing payments through banks, it routes them through people who already trust each other.

You trust your friend for $200. Your friend trusts their colleague for $100. That chain is a payment path. Alice can pay Daniel through Bob and Carol without any of them having a bank account, a credit check, or paying a wire fee.

Getting started takes one thing: one person who trusts you. That's the entire onramp. No KYC, no applications, no waiting.

The network doesn't need millions of users to work. Research on the strength of weak ties and six degrees of separation shows that a few hundred well connected nodes can create viable payment paths across continents.

Three things happen under the hood:

Trust graph routing finds the cheapest path through your social connections. Each person in the chain sets their own fee (often zero for close contacts).

Debt netting detects circular debt and cancels it. If A owes B owes C owes A, the system removes the cycle. In testing, 15 to 30% of total debt disappears without anyone sending money.

Tor privacy routes all traffic through onion routing. We cannot see who pays whom, by design.

Debt can be denominated in anything: dollars, euros, stablecoins, hours, or custom units. eIOU is not a cryptocurrency. No tokens, no coins, no mining. Just bilateral IOUs routed through a social graph.

With stablecoins entering mainstream adoption, people can now denominate trust lines in USD and route real value through personal connections without touching a bank. The infrastructure finally exists for trust based networks to work at scale.

The Docker image and source code are now public. Three commands and you have a running node:

git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker
docker compose up -d

This is open source alpha. The network is small, we're actively developing, and we want feedback from people who build in payments, fintech, or privacy tech.

eiou.org
github.com/eiou-org/eiou-docker

If you're working on cross border payments, alternative credit, or financial privacy, I'd welcome a conversation.

#fintech #payments #opensource
