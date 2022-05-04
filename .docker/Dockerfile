FROM php:8.1-apache

# update the repository sources list
# and install basic dependencies
RUN apt-get update -y \
  && apt-get install -y \
    openssl \
    wget \
    libzip-dev --no-install-recommends

# cleanup
RUN rm -rf /var/lib/apt/lists/*

# install mailhog sendmail
RUN wget -O /usr/local/bin/mhsendmail https://github.com/mailhog/mhsendmail/releases/download/v0.2.0/mhsendmail_linux_amd64
RUN chmod +x /usr/local/bin/mhsendmail

# install & enable xdebug
RUN pecl install xdebug
RUN docker-php-ext-enable xdebug

# install zip extension
RUN docker-php-ext-configure zip
RUN docker-php-ext-install zip

# install composer
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# change uid and gid of apache to docker user uid/gid
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# activate mod rewrite
RUN a2enmod rewrite

# set our application folder as an environment variable
ENV APP_HOME /var/www/html

# set the working dir
WORKDIR $APP_HOME

# change ownership of our application
RUN chown -R www-data:www-data $APP_HOME

# point the document root to the public directory
ENV APACHE_DOCUMENT_ROOT ${APP_HOME}

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}/public!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# run as www-data
USER www-data:www-data