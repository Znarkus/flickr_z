flickr_z
========

A powertool for selecting and downloading images from your flickr account.



## Requirements

* `zip` binary to download images. Works with Windows and *nix.
* nginx web server.



## Install

	git clone git@github.com:Znarkus/flickr_z.git
	cd flickr_z/
	git submodule update --init --recursive

Now that its cloned, you need to configure **nginx**.

Create a new `server {}` block and set it up to your liking, then add this:

	location / {
		try_files $uri /app/index.php$is_args$args;
    }

	location /f/ {
		internal;
		alias "/path/to/flickr_z_root/tmp/";
	}