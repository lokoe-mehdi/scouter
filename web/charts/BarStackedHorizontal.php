<?php
namespace Charts;

class BarStackedHorizontal extends Chart
{

    private $data;
    private $title;
    private $subtitle;
    private $color=['#363340', '#A63C4F', '#96A65D', '#F2C5BB', '#4F6D7A', '#D9BF77', '#6A3E37', '#C2D4D8', '#E07A5F', '#7A9E7E'];

    public function __construct($title,$data,$subtitle="")
    {
        $this->title = $title;
        $this->data = $data;
        $this->subtitle = $subtitle;
    }

    public function draw($id){
       

        $html="<canvas id=\"$id\"></canvas><script type=\"text/javascript\">var ctx = document.getElementById('$id').getContext('2d');

        var data = {
          labels :[";
          $i=0;
              foreach($this->data[array_key_first($this->data)] as $k=>$v){
                if($i>0) { $html.=","; }
                $html.= "' ".$k."'";
                $i++;
              }
          $html.="],
          datasets:[";
            $j=0;
            foreach($this->data as $key=>$val){
              if($j>0) { $html.=","; }
              $j++;
              if(empty(trim($key))) { $key = 'Unclassed'; }
              $html.="{
                label:'$key',
                data:[";
                $i=0;
                foreach($val as $depth){
                  if($i>0) { $html.=","; }
                  $html.= $depth;
                  $i++;
                }  
                $html.="],
                backgroundColor:'".next($this->color)."'
              }";
            }
          $html.="]
        }
        
        var options = {
          title:{
            display:true,
            text:'Depth levels'
          },
          plugins: {
            stacked100: { enable: true }
          },
        
          scales:{
            xAxes:[{
              stacked:true
            }],
            yAxes:[{
              stacked:true,
              scaleLabel:{
                display:true,
                labelString:'% pages'
              }
            }]
          },
          animation: {
            duration: 5,
            onComplete: function () {
                // render the value of the chart above the bar
                var ctx = this.chart.ctx;
                ctx.font = Chart.helpers.fontString(Chart.defaults.global.defaultFontSize, 'normal', Chart.defaults.global.defaultFontFamily);
                ctx.fillStyle = '#fff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                this.data.datasets.forEach(function (dataset) {
                    for (var i = 0; i < dataset.data.length; i++) {
                        var model = dataset._meta[Object.keys(dataset._meta)[0]].data[i]._model;
                        if(dataset.data[i]!=0) { ctx.fillText(dataset.data[i], model.x, model.y + 30); }
                    }
                });
            }}
        
        }
        
        var chart_$id = new Chart(ctx,{
          type:'horizontalBar',
          data:data,
          options:options
        })</script>";
        echo $html;
    }

}