#!/bin/sh

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (HTTP)
echo -e "\nGenerate pubkeys and set hostnames..."
for container in "${containers[@]}"; do
    containerAddress="http://"$container
    docker exec $container eiou generate $containerAddress
    sleep 1

    containerAddresses[$container]=$(docker exec $container php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["hostname"])){echo $json["hostname"];}')
    if [[ "${containerAddresses[${container}]}" == $containerAddress ]]; then
        printf "Generate succesfully run for %s\n" ${container}
    fi
done

