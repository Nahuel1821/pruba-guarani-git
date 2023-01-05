document.getElementById("botton").onclick = function (){
    console.log("capturamos el evento click")
    document.getElementById("demo").innerHTML = "estamos probando nuestro primer evento en js"
}

document.getElementById("color").addEventListener('click', function(){
    document.body.style.backgroundColor = '#FF0000';
})

document.getElementById("ocultar").addEventListener('click', function(){
    document.getElementById('demo').style.display = 'none'
})

const collection = document.getElementsByClassName("prueba");
for (let i = 0; i < collection.length; i++){
    collection[i].style.backgroundColor = "yellow";
}