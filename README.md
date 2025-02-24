# Xdebug

Things to note:
1) Depending on what version of PHP is in use you might need a different version of Xdebug, for which versions of Xdebug is supported for a specific version of PHP see: https://xdebug.org/docs/compat
2) To change internal settings of xdebug you'll need to change/add values to the 99-xdebug.ini file found in the eiou/src/xdebug folder, see https://xdebug.org/docs/all_settings for any and all settings you can use.

## Adding volume to command
  - All you need to do is add "-v name:/var/www/html/" to the run command for example:
    ```
       docker run -d -v eioud0http:/var/www/html/ --network=my-net --name eioud0http eioud
    ```

## How to debug (VScode)
1) Open up the parent folder containing the .vscode folder in VScode, this should be under: \\wsl.localhost\docker-desktop\mnt\docker-desktop-disk\data\docker\volumes\eioud0\_data\eiou (Note: the volume name eioud0)
    - Volumes can be found normally under: \\wsl.localhost\docker-desktop\mnt\docker-desktop-disk\data\docker\volumes\ (Note: this may be different/change with versions of docker)
2) To start debugging, click on the 'little bug crawling' icon, select 'Listen for Xdebug' from the dropdown and hit start: 

![Screenshot 2025-02-24 010643](https://github.com/user-attachments/assets/dd66c06d-b0da-48ff-95eb-a59d71f64d4b)


## launch.json for debugging 
The repository comes with a premade launch.json containing the following:

```json
{
    // Use IntelliSense to learn about possible attributes.
    // Hover to view descriptions of existing attributes.
    // For more information, visit: https://go.microsoft.com/fwlink/?linkid=830387
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "pathMappings": {
                "/var/www/html/eiou/": "${workspaceFolder}",
            },
            "port": 9003
        }
    ]
}
```

## Future things ?:
 - PHPUnit: https://docs.phpunit.de/en/12.0/installation.html
