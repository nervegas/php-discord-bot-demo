This was just a proof of concept to see how difficult it'd be to write a discord client using raw websockets rather than one of the discord bot libs.

To run it you'll need to use composer to install "phrity/websocket" for ws connectivity, then enter your tokens.

To run as a selfbot you'll need to enable the USERAGENT string and remove the 'bot' suffix from the token.

To run as a standard bot you'll need to disable the USERAGENT and make sure the 'bot' suffix is added to your token.
