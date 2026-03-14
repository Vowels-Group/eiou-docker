# Product Hunt Launch

## Tagline
Send money through people you trust. No banks, no fees.

## Description (500 chars max)
eIOU is a peer-to-peer credit network where payments route through people who already trust each other. Instead of banks that charge fees, eIOU finds a path through your relationships. You trust your friend, your friend trusts their colleague, value moves through the chain. Fees as low as 0%. Getting started takes one thing: someone who trusts you. No bank account, no credit check, no KYC. The network works with just a few hundred nodes. Docker image is public. Open source alpha on Android.

## Maker's Comment
Hey Product Hunt! I'm Adrien, founder of eIOU.

Sending money still costs too much. Wire transfers charge $25 to $50. Remittance services take 5 to 10%. Even "free" apps make money on the float and the FX spread.

eIOU takes a completely different approach. Instead of routing through banks and payment processors, your money travels through people you already know and trust. Your friend, your colleague, your business partner. Each person sets their own fee, often zero for people they're close with.

Here's how it works: you open a trust line with someone you know and set a credit limit. They do the same with people they know. When you need to pay someone you've never met, eIOU finds a chain of trust that connects you. A friend of a friend of a friend. Research on the strength of weak ties and six degrees of separation shows this works with surprisingly few people. A few hundred active nodes can create viable payment paths across continents.

The onramp is the simplest part. One person on the network trusts you? You're in. No bank account needed, no credit check, no KYC, no app store payment setup. That's it.

Why credit is easier: traditional lending means convincing a stranger at a bank. On eIOU, credit comes from people who already know you. Your friend trusts you for $200? That's your credit line. No applications, no scoring, no waiting.

A few things that happen automatically:

Chain drops: if A owes B, B owes C, and C owes A, the system detects the cycle and cancels the debt. In testing, 15 to 30% of total debt disappears without anyone sending money.

Tor privacy: all traffic routes through onion routing. We literally cannot see who pays whom. The tradeoff is 3 to 8 seconds of latency per operation.

Debt can be denominated in anything you want: dollars, euros, stablecoins, hours, commodities, or custom units. With stablecoins going mainstream, people can denominate trust lines in USD (or USDC, USDT) and route real value through social connections without touching a bank. eIOU is not a cryptocurrency. No tokens, no coins, no mining.

We just made the source code and Docker image public. To run your own node:

git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker
docker compose up -d

That's it. The container auto generates a wallet, starts Tor, and initializes everything. Ready in about 2 minutes.

GitHub: github.com/eiou-org/eiou-docker
Website: eiou.org

This is open source alpha. The network is small, we're actively developing, and we want to hear what breaks. If you're interested in running your own node or testing the Android app on Google Play, every piece of feedback at this stage shapes what this becomes.
