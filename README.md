This was just a proof of concept to see how difficult it'd be to write a discord client using raw websockets rather than one of the discord bot libs.

To run it you'll need to use composer to install "phrity/websocket" for ws connectivity, then enter your tokens.

This script also makes use of python3-sherlock, so to use that module (it just makes a call to the application) you'll need to either install the sherlock package or get it from https://github.com/sherlock-project/sherlock.git.

To run as a selfbot you'll need to enable the USERAGENT string and remove the 'bot' prefix from the token.
To run as a standard bot you'll need to disable the USERAGENT and make sure the 'bot' prefix is added to your token.

These changes can be made on lines 193:198.
