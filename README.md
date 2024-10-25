# Magezon Core for Magento 2

## Install and Upgrade

### 1. Install via Composer (Recommended)

It is recommended to install Magezon Core module via composer for easy installion, update and maintenance.

Run the following command in Magento 2 root folder.

#### 1.1 Install

```
composer require magezon/module-core
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```

#### 1.2 Upgrade

```
composer update magezon/module-core
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```

Run compile if your store in Product mode:

```
php bin/magento setup:di:compile
```

### 2. Copy and Paste

Use this way if you don't want to install via composer:

- Download [the latest version here](https://github.com/magezon2018/module-core/archive/refs/heads/main.zip) 
- Extract `main.zip` file to `app/code/Magezon/Core`. You should create a folder path `app/code/Magezon/Core` if it does not exist.
- Go to Magento root folder and run upgrade command line to install `Magezon_Core`:

```
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```
